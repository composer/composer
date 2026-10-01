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

namespace Composer\Test\Downloader;

use React\Promise\PromiseInterface;
use Composer\Downloader\ZipDownloader;
use Composer\Package\PackageInterface;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use Composer\Util\Loop;

class ZipDownloaderTest extends TestCase
{
    /** @var string */
    private $testDir;
    /** @var HttpDownloader */
    private $httpDownloader;
    /** @var \Composer\IO\IOInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $io;
    /** @var \Composer\Config&\PHPUnit\Framework\MockObject\MockObject */
    private $config;
    /** @var PackageInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $package;
    /** @var string */
    private $filename;

    public function setUp(): void
    {
        $this->testDir = self::getUniqueTmpDirectory();
        $this->io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $this->config = $this->getMockBuilder('Composer\Config')->getMock();
        $dlConfig = $this->getMockBuilder('Composer\Config')->getMock();
        $this->httpDownloader = new HttpDownloader($this->io, $dlConfig);
        $this->package = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $this->package->expects($this->any())
            ->method('getName')
            ->will($this->returnValue('test/pkg'));

        $this->filename = $this->testDir.'/composer-test.zip';
        file_put_contents($this->filename, 'zip');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $fs = new Filesystem;
        $fs->removeDirectory($this->testDir);
        $this->setPrivateProperty('hasZipArchive', null);
    }

    /**
     * @param mixed $value
     * @param ?MockedZipDownloader $obj
     */
    public function setPrivateProperty(string $name, $value, $obj = null): void
    {
        $reflectionClass = new \ReflectionClass('Composer\Downloader\ZipDownloader');
        $reflectedProperty = $reflectionClass->getProperty($name);
        (\PHP_VERSION_ID < 80100) and $reflectedProperty->setAccessible(true);
        $reflectedProperty->setValue($obj, $value);
    }

    public function testErrorMessages(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('zip extension missing');
        }

        $this->config->expects($this->any())
            ->method('get')
            ->with('vendor-dir')
            ->will($this->returnValue($this->testDir));

        $this->package->expects($this->any())
            ->method('getDistUrl')
            ->will($this->returnValue($distUrl = 'file://'.__FILE__))
        ;
        $this->package->expects($this->any())
            ->method('getDistUrls')
            ->will($this->returnValue([$distUrl]))
        ;
        $this->package->expects($this->atLeastOnce())
            ->method('getTransportOptions')
            ->will($this->returnValue([]))
        ;

        $downloader = new ZipDownloader($this->io, $this->config, $this->httpDownloader);

        try {
            $loop = new Loop($this->httpDownloader);
            $promise = $downloader->download($this->package, $path = sys_get_temp_dir().'/composer-zip-test');
            $loop->wait([$promise]);
            $downloader->install($this->package, $path);

            $this->fail('Download of invalid zip files should throw an exception');
        } catch (\Exception $e) {
            self::assertStringContainsString('is truncated or corrupt, zip end of central directory not found', $e->getMessage());
        }
    }

    public function testTruncatedDownloadIsRetried(): void
    {
        $validZip = (string) file_get_contents(__DIR__.'/../Util/Fixtures/Zip/multiple.zip');
        $io = $this->getIOMock();
        $io->expects([
            ['text' => '{Downloading test/pkg}', 'regex' => true],
            ['text' => '{The downloaded archive for test/pkg is truncated or corrupt, .*, retrying}', 'regex' => true],
            ['text' => '{Downloading test/pkg}', 'regex' => true],
        ], true);

        $attempts = 0;
        $downloader = $this->getDownloaderWithFakeDownloads(static function () use ($validZip, &$attempts): string {
            return ++$attempts === 1 ? substr($validZip, 0, 400) : $validZip;
        }, null, $io);

        $loop = new Loop($this->httpDownloader);
        $loop->wait([$downloader->download($this->getZipPackage(), $this->testDir.'/pkg')]);

        self::assertSame(2, $attempts);
    }

    public function testTruncatedDownloadFailsAfterRetries(): void
    {
        $validZip = (string) file_get_contents(__DIR__.'/../Util/Fixtures/Zip/multiple.zip');
        $attempts = 0;
        $downloader = $this->getDownloaderWithFakeDownloads(static function () use ($validZip, &$attempts): string {
            $attempts++;

            return substr($validZip, 0, 400);
        });

        try {
            $loop = new Loop($this->httpDownloader);
            $loop->wait([$downloader->download($this->getZipPackage(), $this->testDir.'/pkg')]);
            $this->fail('Download of truncated zip files should throw an exception');
        } catch (\Composer\Downloader\TransportException $e) {
            self::assertStringContainsString('The downloaded archive for test/pkg is truncated or corrupt', $e->getMessage());
        }

        self::assertSame(4, $attempts);
    }

    public function testTruncatedCachedFileIsDiscarded(): void
    {
        $validZip = (string) file_get_contents(__DIR__.'/../Util/Fixtures/Zip/multiple.zip');

        $cache = $this->getMockBuilder('Composer\Cache')->disableOriginalConstructor()->getMock();
        $cache->expects($this->once())
            ->method('copyTo')
            ->willReturnCallback(static function ($key, $target) use ($validZip): bool {
                return false !== file_put_contents($target, substr($validZip, 0, 400));
            });
        $cache->expects($this->once())->method('remove');
        $cache->expects($this->once())->method('copyFrom');
        $io = $this->getIOMock();
        $io->expects([
            ['text' => '{Discarding invalid cached archive for test/pkg: .* is truncated or corrupt}', 'regex' => true],
            ['text' => '{Downloading test/pkg}', 'regex' => true],
        ], true);

        $attempts = 0;
        $downloader = $this->getDownloaderWithFakeDownloads(static function () use ($validZip, &$attempts): string {
            $attempts++;

            return $validZip;
        }, $cache, $io);

        $loop = new Loop($this->httpDownloader);
        $loop->wait([$downloader->download($this->getZipPackage(), $this->testDir.'/pkg')]);

        self::assertSame(1, $attempts);
    }

    private function getZipPackage(): \Composer\Package\Package
    {
        $package = self::getPackage('test/pkg', '1.0.0');
        $package->setDistType('zip');
        $package->setDistUrl('https://example.org/test-pkg.zip');

        return $package;
    }

    /**
     * @param callable(): string $contents returns the file content for each download attempt
     */
    private function getDownloaderWithFakeDownloads(callable $contents, ?\Composer\Cache $cache = null, ?\Composer\IO\IOInterface $io = null): ZipDownloader
    {
        $httpDownloader = $this->getMockBuilder('Composer\Util\HttpDownloader')->disableOriginalConstructor()->getMock();
        $httpDownloader->expects($this->any())
            ->method('addCopy')
            ->willReturnCallback(static function ($url, $to) use ($contents) {
                file_put_contents($to, $contents());

                return \React\Promise\resolve(new Response(['url' => $url], 200, [], ''));
            });

        return new ZipDownloader($io ?? $this->io, $this->getConfig(['vendor-dir' => $this->testDir.'/vendor']), $httpDownloader, null, $cache);
    }

    public function testZipArchiveOnlyFailed(): void
    {
        self::expectException('RuntimeException');
        self::expectExceptionMessage('There was an error extracting the ZIP file');
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('zip extension missing');
        }

        $this->setPrivateProperty('hasZipArchive', true);
        $downloader = new MockedZipDownloader($this->io, $this->config, $this->httpDownloader);
        $zipArchive = $this->getMockBuilder('ZipArchive')->getMock();
        $zipArchive->expects($this->once())
            ->method('open')
            ->will($this->returnValue(true));
        $zipArchive->expects($this->once())
            ->method('extractTo')
            ->will($this->returnValue(false));

        $this->setPrivateProperty('zipArchiveObject', $zipArchive, $downloader);
        $promise = $downloader->extract($this->package, $this->filename, 'vendor/dir');
        $this->wait($promise);
    }

    public function testZipArchiveExtractOnlyFailed(): void
    {
        self::expectException('RuntimeException');
        self::expectExceptionMessage('The archive for "test/pkg" may contain identical file names with different capitalization (which fails on case insensitive filesystems): Not a directory');
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('zip extension missing');
        }

        $this->setPrivateProperty('hasZipArchive', true);
        $downloader = new MockedZipDownloader($this->io, $this->config, $this->httpDownloader);
        $zipArchive = $this->getMockBuilder('ZipArchive')->getMock();
        $zipArchive->expects($this->once())
            ->method('open')
            ->will($this->returnValue(true));
        $zipArchive->expects($this->once())
            ->method('extractTo')
            ->will($this->throwException(new \ErrorException('Not a directory')));

        $this->setPrivateProperty('zipArchiveObject', $zipArchive, $downloader);
        $promise = $downloader->extract($this->package, $this->filename, 'vendor/dir');
        $this->wait($promise);
    }

    public function testZipArchiveOnlyGood(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('zip extension missing');
        }

        $this->setPrivateProperty('hasZipArchive', true);
        $downloader = new MockedZipDownloader($this->io, $this->config, $this->httpDownloader);
        $zipArchive = $this->getMockBuilder('ZipArchive')->getMock();
        $zipArchive->expects($this->once())
            ->method('open')
            ->will($this->returnValue(true));
        $zipArchive->expects($this->once())
            ->method('extractTo')
            ->will($this->returnValue(true));
        $zipArchive->expects($this->once())
            ->method('count')
            ->will($this->returnValue(0));

        $this->setPrivateProperty('zipArchiveObject', $zipArchive, $downloader);
        $promise = $downloader->extract($this->package, $this->filename, 'vendor/dir');
        $this->wait($promise);
    }

    public function testSystemUnzipOnlyFailed(): void
    {
        self::expectException('Exception');
        self::expectExceptionMessage('Failed to extract test/pkg: (1) unzip');
        $this->setPrivateProperty('isWindows', false);
        $this->setPrivateProperty('hasZipArchive', false);
        $this->setPrivateProperty('unzipCommands', [['unzip', 'unzip -qq %s -d %s']]);

        $procMock = $this->getMockBuilder('Symfony\Component\Process\Process')->disableOriginalConstructor()->getMock();
        $procMock->expects($this->any())
            ->method('getExitCode')
            ->will($this->returnValue(1));
        $procMock->expects($this->any())
            ->method('isSuccessful')
            ->will($this->returnValue(false));
        $procMock->expects($this->any())
            ->method('getErrorOutput')
            ->will($this->returnValue('output'));

        $processExecutor = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();
        $processExecutor->expects($this->once())
            ->method('executeAsync')
            ->will($this->returnValue(\React\Promise\resolve($procMock)));

        $downloader = new MockedZipDownloader($this->io, $this->config, $this->httpDownloader, null, null, null, $processExecutor);
        $promise = $downloader->extract($this->package, $this->filename, 'vendor/dir');
        $this->wait($promise);
    }

    public function testSystemUnzipOnlyGood(): void
    {
        $this->setPrivateProperty('isWindows', false);
        $this->setPrivateProperty('hasZipArchive', false);
        $this->setPrivateProperty('unzipCommands', [['unzip', 'unzip -qq %s -d %s']]);

        $procMock = $this->getMockBuilder('Symfony\Component\Process\Process')->disableOriginalConstructor()->getMock();
        $procMock->expects($this->any())
            ->method('getExitCode')
            ->will($this->returnValue(0));
        $procMock->expects($this->any())
            ->method('isSuccessful')
            ->will($this->returnValue(true));
        $procMock->expects($this->any())
            ->method('getErrorOutput')
            ->will($this->returnValue('output'));

        $processExecutor = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();
        $processExecutor->expects($this->once())
            ->method('executeAsync')
            ->will($this->returnValue(\React\Promise\resolve($procMock)));

        $downloader = new MockedZipDownloader($this->io, $this->config, $this->httpDownloader, null, null, null, $processExecutor);
        $promise = $downloader->extract($this->package, $this->filename, 'vendor/dir');
        $this->wait($promise);
    }

    public function testNonWindowsFallbackGood(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('zip extension missing');
        }

        $this->setPrivateProperty('isWindows', false);
        $this->setPrivateProperty('hasZipArchive', true);

        $procMock = $this->getMockBuilder('Symfony\Component\Process\Process')->disableOriginalConstructor()->getMock();
        $procMock->expects($this->any())
            ->method('getExitCode')
            ->will($this->returnValue(1));
        $procMock->expects($this->any())
            ->method('isSuccessful')
            ->will($this->returnValue(false));
        $procMock->expects($this->any())
            ->method('getErrorOutput')
            ->will($this->returnValue('output'));

        $processExecutor = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();
        $processExecutor->expects($this->once())
            ->method('executeAsync')
            ->will($this->returnValue(\React\Promise\resolve($procMock)));

        $zipArchive = $this->getMockBuilder('ZipArchive')->getMock();
        $zipArchive->expects($this->once())
            ->method('open')
            ->will($this->returnValue(true));
        $zipArchive->expects($this->once())
            ->method('extractTo')
            ->will($this->returnValue(true));
        $zipArchive->expects($this->once())
            ->method('count')
            ->will($this->returnValue(0));

        $downloader = new MockedZipDownloader($this->io, $this->config, $this->httpDownloader, null, null, null, $processExecutor);
        $this->setPrivateProperty('zipArchiveObject', $zipArchive, $downloader);
        $promise = $downloader->extract($this->package, $this->filename, 'vendor/dir');
        $this->wait($promise);
    }

    public function testNonWindowsFallbackFailed(): void
    {
        self::expectException('Exception');
        self::expectExceptionMessage('There was an error extracting the ZIP file');
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('zip extension missing');
        }

        $this->setPrivateProperty('isWindows', false);
        $this->setPrivateProperty('hasZipArchive', true);

        $procMock = $this->getMockBuilder('Symfony\Component\Process\Process')->disableOriginalConstructor()->getMock();
        $procMock->expects($this->any())
            ->method('getExitCode')
            ->will($this->returnValue(1));
        $procMock->expects($this->any())
            ->method('isSuccessful')
            ->will($this->returnValue(false));
        $procMock->expects($this->any())
            ->method('getErrorOutput')
            ->will($this->returnValue('output'));

        $processExecutor = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();
        $processExecutor->expects($this->once())
          ->method('executeAsync')
          ->will($this->returnValue(\React\Promise\resolve($procMock)));

        $zipArchive = $this->getMockBuilder('ZipArchive')->getMock();
        $zipArchive->expects($this->once())
          ->method('open')
          ->will($this->returnValue(true));
        $zipArchive->expects($this->once())
          ->method('extractTo')
          ->will($this->returnValue(false));

        $downloader = new MockedZipDownloader($this->io, $this->config, $this->httpDownloader, null, null, null, $processExecutor);
        $this->setPrivateProperty('zipArchiveObject', $zipArchive, $downloader);
        $promise = $downloader->extract($this->package, $this->filename, 'vendor/dir');
        $this->wait($promise);
    }

    /**
     * @param ?PromiseInterface<mixed> $promise
     */
    private function wait($promise): void
    {
        if (null === $promise) {
            return;
        }

        $e = null;
        $promise->then(static function (): void {
            // noop
        }, static function ($ex) use (&$e): void {
            $e = $ex;
        });

        if ($e !== null) {
            throw $e;
        }
    }
}

class MockedZipDownloader extends ZipDownloader
{
    public function download(PackageInterface $package, $path, ?PackageInterface $prevPackage = null, bool $output = true): PromiseInterface
    {
        return \React\Promise\resolve(null);
    }

    public function install(PackageInterface $package, $path, bool $output = true): PromiseInterface
    {
        return \React\Promise\resolve(null);
    }

    public function extract(PackageInterface $package, $file, $path): PromiseInterface
    {
        return parent::extract($package, $file, $path);
    }
}
