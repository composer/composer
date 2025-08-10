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
use Composer\Util\HttpDownloader;
use Composer\Util\Loop;

class ZipDownloaderTest extends TestCase
{
    /** @var string */
    private $testDir;
    /** @var \Composer\Util\HttpDownloader */
    private $httpDownloader;
    /** @var \Composer\IO\IOInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $io;
    /** @var \Composer\Config&\PHPUnit\Framework\MockObject\MockObject */
    private $config;
    /** @var \Composer\Package\PackageInterface&\PHPUnit\Framework\MockObject\MockObject */
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
     * @param ?\Composer\Test\Downloader\MockedZipDownloader $obj
     */
    public function setPrivateProperty(string $name, $value, $obj = null): void
    {
        $reflectionClass = new \ReflectionClass('Composer\Downloader\ZipDownloader');
        $reflectedProperty = $reflectionClass->getProperty($name);
        (\PHP_VERSION_ID < 80100) && $reflectedProperty->setAccessible(true);
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
            self::assertStringContainsString('is not a zip archive', $e->getMessage());
        }
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
     * @param ?\React\Promise\PromiseInterface<mixed> $promise
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
