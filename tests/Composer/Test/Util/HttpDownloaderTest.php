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

namespace Composer\Test\Util;

use Composer\IO\BufferIO;
use Composer\Util\Http\CurlDownloader;
use Composer\Util\HttpDownloader;
use PHPUnit\Framework\TestCase;

class HttpDownloaderTest extends TestCase
{
    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&\Composer\Config
     */
    private function getConfigMock()
    {
        $config = $this->getMockBuilder('Composer\Config')->getMock();
        $config->expects($this->any())
            ->method('get')
            ->will($this->returnCallback(static function ($key) {
                if ($key === 'github-domains' || $key === 'gitlab-domains') {
                    return [];
                }
            }));

        return $config;
    }

    /**
     * @group slow
     */
    public function testCaptureAuthenticationParamsFromUrl(): void
    {
        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $io->expects($this->once())
            ->method('setAuthentication')
            ->with($this->equalTo('github.com'), $this->equalTo('user'), $this->equalTo('pass'));

        $fs = new HttpDownloader($io, $this->getConfigMock());
        try {
            $fs->get('https://user:pass@github.com/composer/composer/404');
        } catch (\Composer\Downloader\TransportException $e) {
            self::assertNotEquals(200, $e->getCode());
        }
    }

    public function testPreventUrlAccessCallableBlocksDownload(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('curl extension is required');
        }

        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $downloader = new HttpDownloader($io, $this->getConfigMock());

        $this->expectException(\Composer\Downloader\TransportException::class);
        $this->expectExceptionMessage('Access to "https://example.org/blocked" is blocked.');

        $downloader->get('https://example.org/blocked', [
            'prevent_url_access_callable' => static function (string $url): bool {
                return true;
            },
        ]);
    }

    public function testParkedRetriesDoNotHoldUpQueuedRequests(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('curl extension is required');
        }

        $downloader = new HttpDownloader(new BufferIO(), $this->getConfigMock());
        $downloader->enableAsync();
        $this->setProperty($downloader, 'maxJobs', 1);

        // only one request fits in the slot
        $curl = $this->createCurlMock(0);
        $curl->expects(self::once())->method('download');
        $this->setProperty($downloader, 'curl', $curl);
        $downloader->add('https://example.org/a');
        $downloader->add('https://example.org/b');
        $downloader->countActiveJobs();

        // the first request is parked on a Retry-After, which frees its slot for the second one
        $curl = $this->createCurlMock(1);
        $curl->expects(self::once())->method('download');
        $this->setProperty($downloader, 'curl', $curl);
        $downloader->countActiveJobs();
    }

    public function testNoRequestIsStartedToAnOriginWhichAskedToBeLeftAlone(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('curl extension is required');
        }

        $downloader = new HttpDownloader(new BufferIO(), $this->getConfigMock());
        $downloader->enableAsync();

        // the origin asked to be left alone, so only the request to the other one is sent
        $curl = $this->createCurlMock(0, 'example.org');
        $curl->expects(self::once())->method('download')->with(self::anything(), self::anything(), 'other.org', 'https://other.org/b');
        $this->setProperty($downloader, 'curl', $curl);
        $downloader->add('https://example.org/a');
        $downloader->add('https://other.org/b');
        $downloader->countActiveJobs();

        // once the hold has run out the queued request is sent
        $curl = $this->createCurlMock(0);
        $curl->expects(self::once())->method('download')->with(self::anything(), self::anything(), 'example.org', 'https://example.org/a');
        $this->setProperty($downloader, 'curl', $curl);
        $downloader->countActiveJobs();
    }

    public function testRepeatedWarningsFromAnOriginAreShownOnce(): void
    {
        $io = new BufferIO();
        $rateLimited = ['warning' => 'Rate limited', 'status' => 'error', 'path' => '/a'];
        self::assertTrue(HttpDownloader::outputWarnings($io, 'example.org', $rateLimited));
        // the parts of the body which are not shown do not make a warning a different one
        self::assertTrue(HttpDownloader::outputWarnings($io, 'example.org', ['path' => '/b'] + $rateLimited));
        // repository urls on the same host share the origin's entry
        self::assertTrue(HttpDownloader::outputWarnings($io, 'https://example.org/org-a', $rateLimited));
        self::assertTrue(HttpDownloader::outputWarnings($io, 'other.org', $rateLimited));
        self::assertTrue(HttpDownloader::outputWarnings($io, 'https://other.org:8443', $rateLimited));
        self::assertTrue(HttpDownloader::outputWarnings($io, 'packagist.org', $rateLimited));
        self::assertTrue(HttpDownloader::outputWarnings($io, 'https://repo.packagist.org', $rateLimited));
        self::assertTrue(HttpDownloader::outputWarnings($io, 'example.org', ['warning' => 'Slow down']));
        self::assertFalse(HttpDownloader::outputWarnings($io, 'example.org', ['status' => 'error']));
        self::assertFalse(HttpDownloader::outputWarnings($io, 'example.org', null));

        self::assertSame(
            '<warning>Warning from example.org: Rate limited</warning>'.PHP_EOL
            .'<warning>Warning from other.org: Rate limited</warning>'.PHP_EOL
            .'<warning>Warning from https://other.org:8443: Rate limited</warning>'.PHP_EOL
            .'<warning>Warning from packagist.org: Rate limited</warning>'.PHP_EOL
            .'<warning>Warning from example.org: Slow down</warning>'.PHP_EOL,
            $io->getOutput()
        );

        // another IO does not show them again, until the state is reset
        $otherIo = new BufferIO();
        self::assertTrue(HttpDownloader::outputWarnings($otherIo, 'example.org', $rateLimited));
        self::assertSame('', $otherIo->getOutput());
        HttpDownloader::resetShownWarnings();
        self::assertTrue(HttpDownloader::outputWarnings($otherIo, 'example.org', $rateLimited));
        self::assertSame('<warning>Warning from example.org: Rate limited</warning>'.PHP_EOL, $otherIo->getOutput());
    }

    public function testOutputWarnings(): void
    {
        $io = new BufferIO();
        self::assertFalse(HttpDownloader::outputWarnings($io, '$URL', []));
        self::assertSame('', $io->getOutput());

        // warning/info keys present but filtered out by version constraints => nothing written
        self::assertFalse(HttpDownloader::outputWarnings($io, '$URL', [
            'warning' => 'old warning msg',
            'warning-versions' => '<2.0',
            'warnings' => [
                ['message' => 'should not appear', 'versions' => '<2.2'],
            ],
        ]));
        self::assertSame('', $io->getOutput());

        self::assertTrue(HttpDownloader::outputWarnings($io, '$URL', [
            'warning' => 'old warning msg',
            'warning-versions' => '>=2.0',
            'info' => 'old info msg',
            'info-versions' => '>=2.0',
            'warnings' => [
                ['message' => 'should not appear', 'versions' => '<2.2'],
                ['message' => 'visible warning', 'versions' => '>=2.2-dev'],
            ],
            'infos' => [
                ['message' => 'should not appear', 'versions' => '<2.2'],
                ['message' => 'visible info', 'versions' => '>=2.2-dev'],
            ],
        ]));

        // the <info> tag are consumed by the OutputFormatter, but not <warning> as that is not a default output format
        self::assertSame(
            '<warning>Warning from $URL: old warning msg</warning>'.PHP_EOL.
            'Info from $URL: old info msg'.PHP_EOL.
            '<warning>Warning from $URL: visible warning</warning>'.PHP_EOL.
            'Info from $URL: visible info'.PHP_EOL,
            $io->getOutput()
        );
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&CurlDownloader
     */
    private function createCurlMock(int $delayedJobs, ?string $originOnHold = null)
    {
        $curl = $this->getMockBuilder(CurlDownloader::class)->disableOriginalConstructor()->getMock();
        $curl->method('countDelayedJobs')->willReturn($delayedJobs);
        $curl->method('isOriginOnHold')->willReturnCallback(static function (string $origin) use ($originOnHold): bool {
            return $origin === $originOnHold;
        });

        return $curl;
    }

    /**
     * @param mixed $value
     */
    private function setProperty(HttpDownloader $downloader, string $name, $value): void
    {
        $property = new \ReflectionProperty($downloader, $name);
        if (\PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $property->setValue($downloader, $value);
    }
}
