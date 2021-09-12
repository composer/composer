<?php

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

use Composer\Downloader\ZipDownloader;
use Composer\Package\PackageInterface;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Composer\Util\HttpDownloader;
use Composer\Util\Loop;

class ZipDownloaderTest extends TestCase
{
    /**
     * @var string
     */
    private $testDir;
    private $httpDownloader;
    private $io;
    private $config;
    private $package;

    public function setUp()
    {
        $this->testDir = $this->getUniqueTmpDirectory();
        $this->io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $this->config = $this->getMockBuilder('Composer\Config')->getMock();
        $dlConfig = $this->getMockBuilder('Composer\Config')->getMock();
        $this->httpDownloader = new HttpDownloader($this->io, $dlConfig);
        $this->package = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
    }

    public function tearDown()
    {
        $fs = new Filesystem;
        $fs->removeDirectory($this->testDir);
        $this->setPrivateProperty('hasZipArchive', null);
    }

    public function setPrivateProperty($name, $value, $obj = null)
    {
        $reflectionClass = new \ReflectionClass('Composer\Downloader\ZipDownloader');
        $reflectedProperty = $reflectionClass->getProperty($name);
        $reflectedProperty->setAccessible(true);
        if ($obj === null) {
            $reflectedProperty->setValue($value);
        } else {
            $reflectedProperty->setValue($obj, $value);
        }
    }

    public function testErrorMessages()
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
            ->will($this->returnValue(array($distUrl)))
        ;
        $this->package->expects($this->atLeastOnce())
            ->method('getTransportOptions')
            ->will($this->returnValue(array()))
        ;

        $downloader = new ZipDownloader($this->io, $this->config, $this->httpDownloader);

        try {
            $loop = new Loop($this->httpDownloader);
            $promise = $downloader->download($this->package, $path = sys_get_temp_dir().'/composer-zip-test');
            $loop->wait(array($promise));
            $downloader->install($this->package, $path);

            $this->fail('Download of invalid zip files should throw an exception');
        } catch (\Exception $e) {
            $this->assertStringContainsString('is not a zip archive', $e->getMessage());
        }
    }

    public function testZipArchiveOnlyFailed()
    {
        $this->setExpectedException('RuntimeException', 'There was an error extracting the ZIP file');
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('zip extension missing');
        }

        $this->setPrivateProperty('hasZipArchive', true);
        $downloader = new MockedZipDownloader($this->io, $this->config, $this->httpDownloader);
        $zipArchive = $this->getMockBuilder('ZipArchive')->getMock();
        $zipArchive->expects($this->at(0))
            ->method('open')
            ->will($this->returnValue(true));
        $zipArchive->expects($this->at(1))
            ->method('extractTo')
            ->will($this->returnValue(false));

        $this->setPrivateProperty('zipArchiveObject', $zipArchive, $downloader);
        $promise = $downloader->extract($this->package, 'testfile.zip', 'vendor/dir');
        $this->wait($promise);
    }

    public function testZipArchiveExtractOnlyFailed()
    {
        $this->setExpectedException('RuntimeException', 'The archive may contain identical file names with different capitalization (which fails on case insensitive filesystems): Not a directory');
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('zip extension missing');
        }

        $this->setPrivateProperty('hasZipArchive', true);
        $downloader = new MockedZipDownloader($this->io, $this->config, $this->httpDownloader);
        $zipArchive = $this->getMockBuilder('ZipArchive')->getMock();
        $zipArchive->expects($this->at(0))
            ->method('open')
            ->will($this->returnValue(true));
        $zipArchive->expects($this->at(1))
            ->method('extractTo')
            ->will($this->throwException(new \ErrorException('Not a directory')));

        $this->setPrivateProperty('zipArchiveObject', $zipArchive, $downloader);
        $promise = $downloader->extract($this->package, 'testfile.zip', 'vendor/dir');
        $this->wait($promise);
    }

    public function testZipArchiveOnlyGood()
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('zip extension missing');
        }

        $this->setPrivateProperty('hasZipArchive', true);
        $downloader = new MockedZipDownloader($this->io, $this->config, $this->httpDownloader);
        $zipArchive = $this->getMockBuilder('ZipArchive')->getMock();
        $zipArchive->expects($this->at(0))
            ->method('open')
            ->will($this->returnValue(true));
        $zipArchive->expects($this->at(1))
            ->method('extractTo')
            ->will($this->returnValue(true));

        $this->setPrivateProperty('zipArchiveObject', $zipArchive, $downloader);
        $promise = $downloader->extract($this->package, 'testfile.zip', 'vendor/dir');
        $this->wait($promise);
    }

    public function testSystemUnzipOnlyFailed()
    {
        $this->setExpectedException('Exception', 'Failed to extract : (1) unzip');
        $this->setPrivateProperty('isWindows', false);
        $this->setPrivateProperty('hasZipArchive', false);
        $this->setPrivateProperty('unzipCommands', array(array('unzip', 'unzip -qq %s -d %s')));

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
        $processExecutor->expects($this->at(0))
            ->method('executeAsync')
            ->will($this->returnValue(\React\Promise\resolve($procMock)));

        $downloader = new MockedZipDownloader($this->io, $this->config, $this->httpDownloader, null, null, null, $processExecutor);
        $promise = $downloader->extract($this->package, 'testfile.zip', 'vendor/dir');
        $this->wait($promise);
    }

    public function testSystemUnzipOnlyGood()
    {
        $this->setPrivateProperty('isWindows', false);
        $this->setPrivateProperty('hasZipArchive', false);
        $this->setPrivateProperty('unzipCommands', array(array('unzip', 'unzip -qq %s -d %s')));

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
        $processExecutor->expects($this->at(0))
            ->method('executeAsync')
            ->will($this->returnValue(\React\Promise\resolve($procMock)));

        $downloader = new MockedZipDownloader($this->io, $this->config, $this->httpDownloader, null, null, null, $processExecutor);
        $promise = $downloader->extract($this->package, 'testfile.zip', 'vendor/dir');
        $this->wait($promise);
    }

    public function testNonWindowsFallbackGood()
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
        $processExecutor->expects($this->at(0))
            ->method('executeAsync')
            ->will($this->returnValue(\React\Promise\resolve($procMock)));

        $zipArchive = $this->getMockBuilder('ZipArchive')->getMock();
        $zipArchive->expects($this->at(0))
            ->method('open')
            ->will($this->returnValue(true));
        $zipArchive->expects($this->at(1))
            ->method('extractTo')
            ->will($this->returnValue(true));

        $downloader = new MockedZipDownloader($this->io, $this->config, $this->httpDownloader, null, null, null, $processExecutor);
        $this->setPrivateProperty('zipArchiveObject', $zipArchive, $downloader);
        $promise = $downloader->extract($this->package, 'testfile.zip', 'vendor/dir');
        $this->wait($promise);
    }

    public function testNonWindowsFallbackFailed()
    {
        $this->setExpectedException('Exception', 'There was an error extracting the ZIP file');
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
        $processExecutor->expects($this->at(0))
          ->method('executeAsync')
          ->will($this->returnValue(\React\Promise\resolve($procMock)));

        $zipArchive = $this->getMockBuilder('ZipArchive')->getMock();
        $zipArchive->expects($this->at(0))
          ->method('open')
          ->will($this->returnValue(true));
        $zipArchive->expects($this->at(1))
          ->method('extractTo')
          ->will($this->returnValue(false));

        $downloader = new MockedZipDownloader($this->io, $this->config, $this->httpDownloader, null, null, null, $processExecutor);
        $this->setPrivateProperty('zipArchiveObject', $zipArchive, $downloader);
        $promise = $downloader->extract($this->package, 'testfile.zip', 'vendor/dir');
        $this->wait($promise);
    }

    private function wait($promise)
    {
        if (null === $promise) {
            return;
        }

        $e = null;
        $promise->then(function () {
            // noop
        }, function ($ex) use (&$e) {
            $e = $ex;
        });

        if ($e) {
            throw $e;
        }
    }
}

class MockedZipDownloader extends ZipDownloader
{
    public function download(PackageInterface $package, $path, PackageInterface $prevPackage = null, $output = true)
    {
        return \React\Promise\resolve();
    }

    public function install(PackageInterface $package, $path, $output = true)
    {
        return \React\Promise\resolve();
    }

    public function extract(PackageInterface $package, $file, $path)
    {
        return parent::extract($package, $file, $path);
    }
}
