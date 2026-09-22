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
        (\PHP_VERSION_ID < 80100) and $method->setAccessible(true);
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
        (\PHP_VERSION_ID < 80100) and $method->setAccessible(true);
        $method->invoke($downloader);

        self::assertCount(1, $this->readDelayedJobs($downloader), 'the retry which is not due yet must still be waiting');

        $jobs = $this->readProperty($downloader, 'jobs');
        self::assertIsArray($jobs);
        self::assertCount(1, $jobs, 'the due retry must have been started');

        foreach (array_keys($jobs) as $id) {
            $downloader->abortRequest((int) $id);
        }
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
    private function createJob(int $retries = 0): array
    {
        return [
            'url' => 'https://example.org/packages.json',
            'origin' => 'example.org',
            'attributes' => ['retryAuthFailure' => false, 'redirects' => 0, 'retries' => $retries, 'storeAuth' => false, 'ipResolve' => null],
            'options' => [],
            'filename' => null,
            'resolve' => static function (): void {
            },
            'reject' => static function (): void {
            },
        ];
    }

    private function createDownloader(): CurlDownloader
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

        return new CurlDownloader($this->getMockBuilder('Composer\IO\IOInterface')->getMock(), $config);
    }

    /**
     * @param mixed[] $job
     */
    private function scheduleRetry(CurlDownloader $downloader, array $job, float $delay): void
    {
        $method = new \ReflectionMethod($downloader, 'restartJobWithDelay');
        (\PHP_VERSION_ID < 80100) and $method->setAccessible(true);
        $method->invoke($downloader, $job, $job['url'], $job['attributes'], $delay);
    }

    /**
     * @param  mixed[] $job
     * @return mixed
     */
    private function decideRetry(array $job, Response $response)
    {
        $downloader = $this->createDownloader();
        $method = new \ReflectionMethod($downloader, 'isStatusCodeRetryNeeded');
        (\PHP_VERSION_ID < 80100) and $method->setAccessible(true);

        return $method->invoke($downloader, $job, $response);
    }

    /**
     * @return mixed
     */
    private static function parseRetryAfter(Response $response)
    {
        $method = new \ReflectionMethod(CurlDownloader::class, 'getRetryAfterDelay');
        (\PHP_VERSION_ID < 80100) and $method->setAccessible(true);

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
        (\PHP_VERSION_ID < 80100) and $property->setAccessible(true);

        return $property->getValue($downloader);
    }
}
