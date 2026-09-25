<?php declare(strict_types=1);

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test\Util\Http;

use Composer\IO\BufferIO;
use Composer\IO\IOInterface;
use Composer\Test\TestCase;
use Composer\Util\Http\CurlDownloader;
use Composer\Util\Http\Response;

class CurlDownloaderTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('curl extension is required');
        }
    }

    public function testRetryAfterIsReadAsDelaySeconds(): void
    {
        self::assertSame(42, self::parseRetryAfter($this->createResponse(429, ['Retry-After: 42'])));
    }

    public function testRetryAfterIsReadAsHttpDate(): void
    {
        $delay = self::parseRetryAfter($this->createResponse(429, ['Retry-After: '.gmdate('D, d M Y H:i:s \G\M\T', time() + 120)]));

        self::assertIsInt($delay);
        self::assertGreaterThan(115, $delay);
        self::assertLessThanOrEqual(120, $delay);

        // the obsolete RFC 850 format must be accepted too
        self::assertSame(0, self::parseRetryAfter($this->createResponse(429, ['Retry-After: Sunday, 06-Nov-94 08:49:37 GMT'])));
    }

    public function testRetryAfterInThePastMeansNoWait(): void
    {
        self::assertSame(0, self::parseRetryAfter($this->createResponse(429, ['Retry-After: '.gmdate('D, d M Y H:i:s \G\M\T', time() - 120)])));
    }

    public function testMalformedRetryAfterIsIgnored(): void
    {
        self::assertNull(self::parseRetryAfter($this->createResponse(429, ['Retry-After: not a date'])));
        self::assertNull(self::parseRetryAfter($this->createResponse(429, ['Retry-After: 99999999999999999999'])));
        self::assertNull(self::parseRetryAfter($this->createResponse(429, ['Retry-After: '])));
        self::assertNull(self::parseRetryAfter($this->createResponse(429, [])));
    }

    public function testRateLimitedResponseIsRetriedAfterTheInterval(): void
    {
        self::assertSame(
            ['retry' => true, 'delay' => 5.0],
            $this->decideRetry($this->createJob(), $this->createResponse(429, ['Retry-After: 5']))
        );
    }

    public function testZeroRetryAfterUsesTheDefaultBackoff(): void
    {
        self::assertSame(
            ['retry' => true, 'delay' => null],
            $this->decideRetry($this->createJob(), $this->createResponse(429, ['Retry-After: 0']))
        );
    }

    public function testRateLimitedResponseWithoutRetryAfterIsNotRetried(): void
    {
        self::assertSame(
            ['retry' => false, 'delay' => null],
            $this->decideRetry($this->createJob(), $this->createResponse(429, []))
        );
    }

    public function testRetryableStatusCodeHonorsRetryAfter(): void
    {
        self::assertSame(
            ['retry' => true, 'delay' => 30.0],
            $this->decideRetry($this->createJob(), $this->createResponse(503, ['Retry-After: 30']))
        );
    }

    public function testRetryableStatusCodeWithoutRetryAfterUsesTheDefaultBackoff(): void
    {
        self::assertSame(
            ['retry' => true, 'delay' => null],
            $this->decideRetry($this->createJob(), $this->createResponse(503, []))
        );

        self::assertSame(
            ['retry' => true, 'delay' => null],
            $this->decideRetry($this->createJob(), $this->createResponse(503, ['Retry-After: whenever']))
        );
    }

    public function testRetryAfterBeyondTheCapIsNotWaitedOut(): void
    {
        // 429 is only retryable because the header says when to come back, so an interval we will
        // not wait for leaves nothing to act on
        self::assertSame(
            ['retry' => false, 'delay' => null],
            $this->decideRetry($this->createJob(), $this->createResponse(429, ['Retry-After: 3600']))
        );

        // a status code which was retryable before this header was read must stay retryable, on the
        // default backoff, so the cap cannot turn a retry we would have made anyway into a failure
        self::assertSame(
            ['retry' => true, 'delay' => null],
            $this->decideRetry($this->createJob(), $this->createResponse(503, ['Retry-After: 3600']))
        );
    }

    public function testRetryAfterDoesNotDefeatMaxRetries(): void
    {
        self::assertSame(
            ['retry' => false, 'delay' => null],
            $this->decideRetry($this->createJob(3), $this->createResponse(429, ['Retry-After: 5']))
        );
    }

    public function testNonGetRequestsAreNotRetried(): void
    {
        $job = $this->createJob();
        $job['options'] = ['http' => ['method' => 'POST']];

        self::assertSame(
            ['retry' => false, 'delay' => null],
            $this->decideRetry($job, $this->createResponse(429, ['Retry-After: 5']))
        );
    }

    public function testDelayedRetryDoesNotBlockTheDownloadLoop(): void
    {
        $downloader = $this->createDownloader();

        // tick() drives every transfer which is in flight, so a delay which is waited out rather
        // than scheduled stalls all of them, not just the request which is being retried
        $start = microtime(true);
        $this->scheduleRetry($downloader, $this->createJob(), 30.0);
        $downloader->tick();
        $elapsed = microtime(true) - $start;

        self::assertLessThan(1.0, $elapsed);
        self::assertCount(1, $this->readDelayedJobs($downloader));
    }

    public function testSelectTimeoutIsShortenedToTheNextDueRetry(): void
    {
        $downloader = $this->createDownloader();
        $this->scheduleRetry($downloader, $this->createJob(), 0.25);

        $timeout = self::callPrivate($downloader, 'getSelectTimeout');

        self::assertIsFloat($timeout);
        self::assertGreaterThan(0.0, $timeout);
        self::assertLessThanOrEqual(0.25, $timeout);
    }

    public function testDueRetryIsStartedWhileALaterOneKeepsWaiting(): void
    {
        $downloader = $this->createDownloader();
        $this->scheduleRetry($downloader, $this->createJob(0, 'other.org'), 30.0);
        $this->scheduleRetry($downloader, $this->createJob(), 0.0001);
        usleep(1000);

        self::callPrivate($downloader, 'restartDueJobs');

        self::assertCount(1, $this->readDelayedJobs($downloader), 'the retry which is not due yet must still be waiting');

        $jobs = $this->readProperty($downloader, 'jobs');
        self::assertIsArray($jobs);
        self::assertCount(1, $jobs, 'the due retry must have been started');

        foreach (array_keys($jobs) as $id) {
            $downloader->abortRequest((int) $id);
        }
    }

    public function testAbortedRequestIsNotRetried(): void
    {
        $downloader = $this->createDownloader();
        $job = $this->createJob();
        $this->scheduleRetry($downloader, $job, 30.0);

        // a request is cancelled by the id of the handle it was first sent on
        $downloader->abortRequest((int) $job['curlHandle']);
        self::assertSame([], $this->readDelayedJobs($downloader));

        $this->letWaitsRunOut($downloader);
        self::callPrivate($downloader, 'restartDueJobs');
        self::assertSame([], $this->readProperty($downloader, 'jobs'), 'a cancelled request must not be sent again');
    }

    public function testRetryWaitsForTheLatestRetryAfterOfItsOrigin(): void
    {
        $io = new BufferIO();
        $downloader = $this->createDownloader($io);
        // another request to example.org was told to come back in 30s, which holds for the next ones too
        $this->scheduleRetry($downloader, $this->createJob(), 30.0);
        $this->scheduleRetry($downloader, $this->createJob(), 0.0001);
        $this->scheduleRetry($downloader, $this->createJob(3), null);
        $this->scheduleRetry($downloader, $this->createJob(0, 'other.org'), 0.0001);
        usleep(1000);

        self::callPrivate($downloader, 'restartDueJobs');

        $delayedJobs = $this->readDelayedJobs($downloader);
        self::assertCount(3, $delayedJobs, 'retries to example.org must wait for its deadline, whether or not they got a Retry-After');
        foreach ($delayedJobs as $delayedJob) {
            self::assertSame('example.org', $delayedJob['job']['origin']);
        }

        $jobs = $this->readProperty($downloader, 'jobs');
        self::assertIsArray($jobs);
        self::assertCount(1, $jobs, 'a retry to another origin must not be held up');
        foreach (array_keys($jobs) as $id) {
            $downloader->abortRequest((int) $id);
        }

        $this->announceRetryAfterWaits($downloader);
        self::assertStringContainsString('(3 pending), waiting 30s before retrying', $io->getOutput());
    }

    public function testFirstRetryToABlockedOriginIsNotStartedStraightAway(): void
    {
        $downloader = $this->createDownloader();
        $this->scheduleRetry($downloader, $this->createJob(), 30.0);

        // the first retry has no backoff of its own
        $this->scheduleRetry($downloader, $this->createJob(0), null);

        self::assertCount(2, $this->readDelayedJobs($downloader));
        self::assertSame([], $this->readProperty($downloader, 'jobs'));
    }

    public function testRetryAfterWaitIsAnnouncedOncePerOrigin(): void
    {
        $io = new BufferIO();
        $downloader = $this->createDownloader($io);
        $this->scheduleRetry($downloader, $this->createJob(), 10.0);
        $this->scheduleRetry($downloader, $this->createJob(), 30.0);
        $this->scheduleRetry($downloader, $this->createJob(0, 'other.org'), 30.0);

        $this->announceRetryAfterWaits($downloader);
        $this->announceRetryAfterWaits($downloader);

        self::assertSame(
            '<warning>example.org is rate limiting requests (2 pending), waiting 30s before retrying</warning>'.PHP_EOL
            .'<warning>other.org is rate limiting requests (1 pending), waiting 30s before retrying</warning>'.PHP_EOL,
            $io->getOutput()
        );
    }

    public function testRetryAfterWaitIsAnnouncedWhileRequestsToTheOriginAreStillInFlight(): void
    {
        $io = new BufferIO();
        $downloader = $this->createDownloader($io);

        // a long transfer to the origin can outlast the whole wait, so the notice must not wait for it
        $this->scheduleRetry($downloader, $this->createJob(), 30.0);
        $this->writeProperty($downloader, 'jobs', [1 => ['origin' => 'example.org']]);
        $this->announceRetryAfterWaits($downloader);

        self::assertSame('<warning>example.org is rate limiting requests (1 pending), waiting 30s before retrying</warning>'.PHP_EOL, $io->getOutput());
    }

    public function testRetryAfterWaitIsAnnouncedAgainOnceTheOriginTurnsRequestsAwayAnew(): void
    {
        $io = new BufferIO();
        $downloader = $this->createDownloader($io);
        $this->scheduleRetry($downloader, $this->createJob(), 1.0, 503);
        $this->announceRetryAfterWaits($downloader);

        // the wait runs out and the retry is started, which ends the episode
        $this->letWaitsRunOut($downloader);
        self::callPrivate($downloader, 'restartDueJobs');
        $this->announceRetryAfterWaits($downloader);
        $jobs = $this->readProperty($downloader, 'jobs');
        self::assertIsArray($jobs);
        foreach (array_keys($jobs) as $id) {
            $downloader->abortRequest((int) $id);
        }

        $this->scheduleRetry($downloader, $this->createJob(1), 5.0);
        $this->announceRetryAfterWaits($downloader);

        self::assertSame(
            '<warning>example.org responded with status code 503 (1 pending), waiting 1s before retrying</warning>'.PHP_EOL
            .'<warning>example.org is rate limiting requests (1 pending), waiting 5s before retrying</warning>'.PHP_EOL,
            $io->getOutput()
        );
    }

    public function testDefaultBackoffIsNotAnnounced(): void
    {
        $io = new BufferIO();
        $downloader = $this->createDownloader($io);
        $this->scheduleRetry($downloader, $this->createJob(3), null);

        $this->announceRetryAfterWaits($downloader);

        self::assertCount(1, $this->readDelayedJobs($downloader));
        self::assertSame('', $io->getOutput());
    }

    public function testJsonResponseIsRecognisedWithCharset(): void
    {
        self::assertTrue(self::callPrivate(CurlDownloader::class, 'isJsonResponse', $this->createResponse(429, ['Content-Type: application/json'])));
        self::assertTrue(self::callPrivate(CurlDownloader::class, 'isJsonResponse', $this->createResponse(429, ['Content-Type: application/json; charset=utf-8'])));
        self::assertTrue(self::callPrivate(CurlDownloader::class, 'isJsonResponse', $this->createResponse(429, ['Content-Type: Application/JSON; charset=UTF-8'])));
        self::assertFalse(self::callPrivate(CurlDownloader::class, 'isJsonResponse', $this->createResponse(429, ['Content-Type: text/html'])));
        self::assertFalse(self::callPrivate(CurlDownloader::class, 'isJsonResponse', $this->createResponse(429, [])));
    }

    public function testFailureReportsARetryAfterWhichWasNotWaitedOut(): void
    {
        self::assertSame(
            'HTTP/1.1 429 Status, the server asked to retry in 3600s which is longer than the 60s Composer is willing to wait, try again later',
            self::callPrivate(CurlDownloader::class, 'getStatusFailureMessage', $this->createResponse(429, ['Retry-After: 3600']))
        );

        // an interval which was waited out, or no interval at all, has nothing to add
        self::assertSame('HTTP/1.1 429 Status', self::callPrivate(CurlDownloader::class, 'getStatusFailureMessage', $this->createResponse(429, ['Retry-After: 5'])));
        self::assertSame('HTTP/1.1 429 Status', self::callPrivate(CurlDownloader::class, 'getStatusFailureMessage', $this->createResponse(429, [])));
    }

    public function testRepeatedWarningsFromAnOriginAreShownOnce(): void
    {
        $io = new BufferIO();
        $downloader = $this->createDownloader($io);
        $rateLimited = ['warning' => 'Rate limited', 'status' => 'error', 'path' => '/a'];
        self::assertTrue(self::callPrivate($downloader, 'outputWarnings', 'example.org', $rateLimited));
        // the parts of the body which are not shown do not make a warning a different one
        self::assertTrue(self::callPrivate($downloader, 'outputWarnings', 'example.org', ['path' => '/b'] + $rateLimited));
        self::assertTrue(self::callPrivate($downloader, 'outputWarnings', 'other.org', $rateLimited));
        self::assertTrue(self::callPrivate($downloader, 'outputWarnings', 'example.org', ['warning' => 'Slow down']));
        self::assertFalse(self::callPrivate($downloader, 'outputWarnings', 'example.org', ['status' => 'error']));
        self::assertFalse(self::callPrivate($downloader, 'outputWarnings', 'example.org', null));

        self::assertSame(
            '<warning>Warning from example.org: Rate limited</warning>'.PHP_EOL
            .'<warning>Warning from other.org: Rate limited</warning>'.PHP_EOL
            .'<warning>Warning from example.org: Slow down</warning>'.PHP_EOL,
            $io->getOutput()
        );
    }

    /**
     * @param list<string> $headers
     */
    private function createResponse(int $statusCode, array $headers): Response
    {
        array_unshift($headers, 'HTTP/1.1 '.$statusCode.' Status');

        return new Response(['url' => 'https://example.org/packages.json'], $statusCode, $headers, '');
    }

    /**
     * @return mixed[]
     */
    private function createJob(int $retries = 0, string $origin = 'example.org'): array
    {
        return [
            'url' => 'https://'.$origin.'/packages.json',
            'origin' => $origin,
            'attributes' => ['retryAuthFailure' => false, 'redirects' => 0, 'retries' => $retries, 'storeAuth' => false, 'ipResolve' => null],
            'options' => [],
            'filename' => null,
            'curlHandle' => curl_init(),
            'resolve' => static function (): void {
            },
            'reject' => static function (): void {
            },
        ];
    }

    private function createDownloader(?IOInterface $io = null): CurlDownloader
    {
        $config = $this->getMockBuilder('Composer\Config')->getMock();
        $config->expects($this->any())
            ->method('get')
            ->will($this->returnCallback(static function ($key) {
                if ($key === 'github-domains' || $key === 'gitlab-domains') {
                    return [];
                }

                return null;
            }));

        return new CurlDownloader($io ?? $this->getMockBuilder('Composer\IO\IOInterface')->getMock(), $config);
    }

    /**
     * @param mixed[] $job
     */
    private function scheduleRetry(CurlDownloader $downloader, array $job, ?float $delay, int $statusCode = 429): void
    {
        self::callPrivate($downloader, 'restartJobWithDelay', $job, $job['url'], $job['attributes'], $delay, $statusCode);
    }

    private function announceRetryAfterWaits(CurlDownloader $downloader): void
    {
        self::callPrivate($downloader, 'announceRetryAfterWaits');
    }

    /**
     * @param  mixed[] $job
     * @return mixed
     */
    private function decideRetry(array $job, Response $response)
    {
        return self::callPrivate($this->createDownloader(), 'isStatusCodeRetryNeeded', $job, $response);
    }

    /**
     * @return mixed
     */
    private static function parseRetryAfter(Response $response)
    {
        return self::callPrivate(CurlDownloader::class, 'getRetryAfterDelay', $response);
    }

    /**
     * @param  object|class-string $target
     * @param  mixed               ...$args
     * @return mixed
     */
    private static function callPrivate($target, string $method, ...$args)
    {
        $reflection = new \ReflectionMethod($target, $method);
        if (\PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }

        return $reflection->invoke(is_object($target) ? $target : null, ...$args);
    }

    /**
     * @return mixed[]
     */
    private function readDelayedJobs(CurlDownloader $downloader): array
    {
        $delayedJobs = $this->readProperty($downloader, 'delayedJobs');
        self::assertIsArray($delayedJobs);

        return $delayedJobs;
    }

    /**
     * @return mixed
     */
    private function readProperty(CurlDownloader $downloader, string $name)
    {
        $property = new \ReflectionProperty($downloader, $name);
        if (\PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }

        return $property->getValue($downloader);
    }

    /**
     * Moves every delayed retry and origin hold into the past, as if their wait had run out
     */
    private function letWaitsRunOut(CurlDownloader $downloader): void
    {
        $delayedJobs = $this->readDelayedJobs($downloader);
        foreach (array_keys($delayedJobs) as $i) {
            $delayedJobs[$i]['at'] = 0.0;
        }
        $this->writeProperty($downloader, 'delayedJobs', $delayedJobs);

        $origins = $this->readProperty($downloader, 'retryAfterOrigins');
        self::assertIsArray($origins);
        foreach (array_keys($origins) as $origin) {
            $origins[$origin]['until'] = 0.0;
        }
        $this->writeProperty($downloader, 'retryAfterOrigins', $origins);
    }

    /**
     * @param mixed $value
     */
    private function writeProperty(CurlDownloader $downloader, string $name, $value): void
    {
        $property = new \ReflectionProperty($downloader, $name);
        if (\PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $property->setValue($downloader, $value);
    }
}
