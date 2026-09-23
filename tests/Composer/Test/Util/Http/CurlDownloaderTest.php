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

        $method = new \ReflectionMethod($downloader, 'getSelectTimeout');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $timeout = $method->invoke($downloader);

        self::assertIsFloat($timeout);
        self::assertGreaterThan(0.0, $timeout);
        self::assertLessThanOrEqual(0.25, $timeout);
    }

    public function testDueRetryIsStartedWhileALaterOneKeepsWaiting(): void
    {
        $downloader = $this->createDownloader();
        $this->scheduleRetry($downloader, $this->createJob(), 30.0);
        $this->scheduleRetry($downloader, $this->createJob(), 0.0001);
        usleep(1000);

        $method = new \ReflectionMethod($downloader, 'restartDueJobs');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $method->invoke($downloader);

        self::assertCount(1, $this->readDelayedJobs($downloader), 'the retry which is not due yet must still be waiting');

        $jobs = $this->readProperty($downloader, 'jobs');
        self::assertIsArray($jobs);
        self::assertCount(1, $jobs, 'the due retry must have been started');

        foreach (array_keys($jobs) as $id) {
            $downloader->abortRequest((int) $id);
        }
    }

    public function testRetryWaitsForTheLatestRetryAfterOfItsOrigin(): void
    {
        $io = new BufferIO();
        $downloader = $this->createDownloader($io);
        // another request to example.org was told to come back in 30s, which holds for this one too
        $this->startRetryAfterEpisode($downloader, 'example.org', 429, microtime(true) + 30);
        $this->scheduleRetry($downloader, $this->createJob(), 0.0001);
        $this->scheduleRetry($downloader, $this->createJob(3), null);
        $this->scheduleRetry($downloader, $this->createJob(0, 'other.org'), 0.0001);
        usleep(1000);

        $method = new \ReflectionMethod($downloader, 'restartDueJobs');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $method->invoke($downloader);

        $delayedJobs = $this->readDelayedJobs($downloader);
        self::assertCount(2, $delayedJobs, 'retries to example.org must wait for its deadline, whether or not they got a Retry-After');
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
        self::assertStringContainsString('(1 pending), waiting 30s before retrying', $io->getOutput());
    }

    public function testFirstRetryToABlockedOriginIsNotStartedStraightAway(): void
    {
        $downloader = $this->createDownloader();
        $this->startRetryAfterEpisode($downloader, 'example.org', 429, microtime(true) + 30);

        // the first retry has no backoff of its own
        $this->scheduleRetry($downloader, $this->createJob(0), null);

        self::assertCount(1, $this->readDelayedJobs($downloader));
        self::assertSame([], $this->readProperty($downloader, 'jobs'));
    }

    public function testRetryAfterWaitIsAnnouncedOncePerOrigin(): void
    {
        $io = new BufferIO();
        $downloader = $this->createDownloader($io);
        $this->startRetryAfterEpisode($downloader, 'example.org', 429);
        $this->scheduleRetry($downloader, $this->createJob(), 10.0);
        $this->scheduleRetry($downloader, $this->createJob(), 30.0);
        $this->scheduleRetry($downloader, $this->createJob(0, 'other.org'), 30.0);

        $this->announceRetryAfterWaits($downloader);
        $this->announceRetryAfterWaits($downloader);

        self::assertSame('<warning>example.org is rate limiting requests (2 pending), waiting 30s before retrying</warning>'.PHP_EOL, $io->getOutput());
    }

    public function testRetryAfterWaitIsAnnouncedOnceNoRequestToTheOriginIsInFlight(): void
    {
        $io = new BufferIO();
        $downloader = $this->createDownloader($io);
        $jobs = new \ReflectionProperty($downloader, 'jobs');
        if (\PHP_VERSION_ID < 80100) {
            $jobs->setAccessible(true);
        }

        // the first of several parallel requests is turned away while the others are still in flight
        $this->startRetryAfterEpisode($downloader, 'example.org', 429);
        $this->scheduleRetry($downloader, $this->createJob(), 5.0);
        $jobs->setValue($downloader, [1 => ['origin' => 'example.org'], 2 => ['origin' => 'example.org'], 3 => ['origin' => 'other.org']]);
        $this->announceRetryAfterWaits($downloader);
        self::assertSame('', $io->getOutput());

        // the others are turned away too, which leaves only requests to other origins in flight
        $this->scheduleRetry($downloader, $this->createJob(), 10.0);
        $this->scheduleRetry($downloader, $this->createJob(), 30.0);
        $jobs->setValue($downloader, [3 => ['origin' => 'other.org']]);
        $this->announceRetryAfterWaits($downloader);

        self::assertSame('<warning>example.org is rate limiting requests (3 pending), waiting 30s before retrying</warning>'.PHP_EOL, $io->getOutput());
    }

    public function testRetryAfterWaitIsAnnouncedAgainOnceTheOriginTurnsRequestsAwayAnew(): void
    {
        $io = new BufferIO();
        $downloader = $this->createDownloader($io);
        $this->startRetryAfterEpisode($downloader, 'example.org', 503);
        $this->scheduleRetry($downloader, $this->createJob(), 0.0001);
        $this->announceRetryAfterWaits($downloader);

        // the retry is started, which ends the wait
        usleep(1000);
        $method = new \ReflectionMethod($downloader, 'restartDueJobs');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $method->invoke($downloader);
        $this->announceRetryAfterWaits($downloader);
        $jobs = $this->readProperty($downloader, 'jobs');
        self::assertIsArray($jobs);
        foreach (array_keys($jobs) as $id) {
            $downloader->abortRequest((int) $id);
        }

        $this->startRetryAfterEpisode($downloader, 'example.org', 429);
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
        $method = new \ReflectionMethod(CurlDownloader::class, 'isJsonResponse');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        self::assertTrue($method->invoke(null, $this->createResponse(429, ['Content-Type: application/json'])));
        self::assertTrue($method->invoke(null, $this->createResponse(429, ['Content-Type: application/json; charset=utf-8'])));
        self::assertTrue($method->invoke(null, $this->createResponse(429, ['Content-Type: Application/JSON; charset=UTF-8'])));
        self::assertFalse($method->invoke(null, $this->createResponse(429, ['Content-Type: text/html'])));
        self::assertFalse($method->invoke(null, $this->createResponse(429, [])));
    }

    public function testFailureReportsARetryAfterWhichWasNotWaitedOut(): void
    {
        $method = new \ReflectionMethod(CurlDownloader::class, 'getStatusFailureMessage');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        self::assertSame(
            'HTTP/1.1 429 Status, the server asked to retry in 3600s which is longer than the 60s Composer is willing to wait, try again later',
            $method->invoke(null, $this->createResponse(429, ['Retry-After: 3600']))
        );

        // an interval which was waited out, or no interval at all, has nothing to add
        self::assertSame('HTTP/1.1 429 Status', $method->invoke(null, $this->createResponse(429, ['Retry-After: 5'])));
        self::assertSame('HTTP/1.1 429 Status', $method->invoke(null, $this->createResponse(429, [])));
    }

    public function testRepeatedWarningsFromAnOriginAreShownOnce(): void
    {
        $io = new BufferIO();
        $downloader = $this->createDownloader($io);
        $method = new \ReflectionMethod($downloader, 'outputWarnings');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        $rateLimited = ['warning' => 'Rate limited', 'status' => 'error', 'path' => '/a'];
        self::assertTrue($method->invoke($downloader, 'example.org', $rateLimited));
        // the parts of the body which are not shown do not make a warning a different one
        self::assertTrue($method->invoke($downloader, 'example.org', ['path' => '/b'] + $rateLimited));
        self::assertTrue($method->invoke($downloader, 'other.org', $rateLimited));
        self::assertTrue($method->invoke($downloader, 'example.org', ['warning' => 'Slow down']));
        self::assertFalse($method->invoke($downloader, 'example.org', ['status' => 'error']));
        self::assertFalse($method->invoke($downloader, 'example.org', null));

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
    private function scheduleRetry(CurlDownloader $downloader, array $job, ?float $delay): void
    {
        $method = new \ReflectionMethod($downloader, 'restartJobWithDelay');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $method->invoke($downloader, $job, $job['url'], $job['attributes'], $delay);
    }

    /**
     * Does what tick() does when a response asks for a retry later
     */
    private function startRetryAfterEpisode(CurlDownloader $downloader, string $origin, int $statusCode, float $until = 0.0): void
    {
        $property = new \ReflectionProperty($downloader, 'retryAfterOrigins');
        if (\PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $origins = $property->getValue($downloader);
        self::assertIsArray($origins);
        $origins[$origin] = $origins[$origin] ?? ['statusCode' => $statusCode, 'announced' => false, 'until' => $until];
        $property->setValue($downloader, $origins);
    }

    private function announceRetryAfterWaits(CurlDownloader $downloader): void
    {
        $method = new \ReflectionMethod($downloader, 'announceRetryAfterWaits');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $method->invoke($downloader);
    }

    /**
     * @param  mixed[] $job
     * @return mixed
     */
    private function decideRetry(array $job, Response $response)
    {
        $downloader = $this->createDownloader();
        $method = new \ReflectionMethod($downloader, 'isStatusCodeRetryNeeded');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        return $method->invoke($downloader, $job, $response);
    }

    /**
     * @return mixed
     */
    private static function parseRetryAfter(Response $response)
    {
        $method = new \ReflectionMethod(CurlDownloader::class, 'getRetryAfterDelay');
        if (\PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        return $method->invoke(null, $response);
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
}
