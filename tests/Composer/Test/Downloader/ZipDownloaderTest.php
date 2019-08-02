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
        $this->setPrivateProperty('hasSystemUnzip', null);
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

    /**
     * @group only
     */
    public function testErrorMessages()
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('zip extension missing');
        }

        $this->config->expects($this->at(0))
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

        $this->setPrivateProperty('hasSystemUnzip', false);

        try {
            $promise = $downloader->download($this->package, $path = sys_get_temp_dir().'/composer-zip-test');
            $loop = new Loop($this->httpDownloader);
            $loop->wait(array($promise));
            $downloader->install($this->package, $path);

            $this->fail('Download of invalid zip files should throw an exception');
        } catch (\Exception $e) {
            $this->assertContains('is not a zip archive', $e->getMessage());
        }
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage There was an error extracting the ZIP file
     */
    public function testZipArchiveOnlyFailed()
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('zip extension missing');
        }

        $this->setPrivateProperty('hasSystemUnzip', false);
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
        $downloader->extract($this->package, 'testfile.zip', 'vendor/dir');
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage The archive may contain identical file names with different capitalization (which fails on case insensitive filesystems): Not a directory
     */
    public function testZipArchiveExtractOnlyFailed()
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('zip extension missing');
        }

        $this->setPrivateProperty('hasSystemUnzip', false);
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
        $downloader->extract($this->package, 'testfile.zip', 'vendor/dir');
    }

    /**
     * @group only
     */
    public function testZipArchiveOnlyGood()
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('zip extension missing');
        }

        $this->setPrivateProperty('hasSystemUnzip', false);
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
        $downloader->extract($this->package, 'testfile.zip', 'vendor/dir');
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Failed to execute unzip
     */
    public function testSystemUnzipOnlyFailed()
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('zip extension missing');
        }

        $this->setPrivateProperty('hasSystemUnzip', true);
        $this->setPrivateProperty('hasZipArchive', false);
        $processExecutor = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();
        $processExecutor->expects($this->at(0))
            ->method('execute')
            ->will($this->returnValue(1));

        $downloader = new MockedZipDownloader($this->io, $this->config, $this->httpDownloader, null, null, $processExecutor);
        $downloader->extract($this->package, 'testfile.zip', 'vendor/dir');
    }

    public function testSystemUnzipOnlyGood()
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('zip extension missing');
        }

        $this->setPrivateProperty('hasSystemUnzip', true);
        $this->setPrivateProperty('hasZipArchive', false);
        $processExecutor = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();
        $processExecutor->expects($this->at(0))
            ->method('execute')
            ->will($this->returnValue(0));

        $downloader = new MockedZipDownloader($this->io, $this->config, $this->httpDownloader, null, null, $processExecutor);
        $downloader->extract($this->package, 'testfile.zip', 'vendor/dir');
    }

    public function testNonWindowsFallbackGood()
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('zip extension missing');
        }

        $this->setPrivateProperty('isWindows', false);
        $this->setPrivateProperty('hasSystemUnzip', true);
        $this->setPrivateProperty('hasZipArchive', true);

        $processExecutor = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();
        $processExecutor->expects($this->at(0))
            ->method('execute')
            ->will($this->returnValue(1));

        $zipArchive = $this->getMockBuilder('ZipArchive')->getMock();
        $zipArchive->expects($this->at(0))
            ->method('open')
            ->will($this->returnValue(true));
        $zipArchive->expects($this->at(1))
            ->method('extractTo')
            ->will($this->returnValue(true));

        $downloader = new MockedZipDownloader($this->io, $this->config, $this->httpDownloader, null, null, $processExecutor);
        $this->setPrivateProperty('zipArchiveObject', $zipArchive, $downloader);
        $downloader->extract($this->package, 'testfile.zip', 'vendor/dir');
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage There was an error extracting the ZIP file
     */
    public function testNonWindowsFallbackFailed()
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('zip extension missing');
        }

        $this->setPrivateProperty('isWindows', false);
        $this->setPrivateProperty('hasSystemUnzip', true);
        $this->setPrivateProperty('hasZipArchive', true);

        $processExecutor = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();
        $processExecutor->expects($this->at(0))
          ->method('execute')
          ->will($this->returnValue(1));

        $zipArchive = $this->getMockBuilder('ZipArchive')->getMock();
        $zipArchive->expects($this->at(0))
          ->method('open')
          ->will($this->returnValue(true));
        $zipArchive->expects($this->at(1))
          ->method('extractTo')
          ->will($this->returnValue(false));

        $downloader = new MockedZipDownloader($this->io, $this->config, $this->httpDownloader, null, null, $processExecutor);
        $this->setPrivateProperty('zipArchiveObject', $zipArchive, $downloader);
        $downloader->extract($this->package, 'testfile.zip', 'vendor/dir');
    }

    public function testWindowsFallbackGood()
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('zip extension missing');
        }

        $this->setPrivateProperty('isWindows', true);
        $this->setPrivateProperty('hasSystemUnzip', true);
        $this->setPrivateProperty('hasZipArchive', true);

        $processExecutor = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();
        $processExecutor->expects($this->atLeastOnce())
            ->method('execute')
            ->will($this->returnValue(0));

        $zipArchive = $this->getMockBuilder('ZipArchive')->getMock();
        $zipArchive->expects($this->at(0))
            ->method('open')
            ->will($this->returnValue(true));
        $zipArchive->expects($this->at(1))
            ->method('extractTo')
            ->will($this->returnValue(false));

        $downloader = new MockedZipDownloader($this->io, $this->config, $this->httpDownloader, null, null, $processExecutor);
        $this->setPrivateProperty('zipArchiveObject', $zipArchive, $downloader);
        $downloader->extract($this->package, 'testfile.zip', 'vendor/dir');
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Failed to execute unzip
     */
    public function testWindowsFallbackFailed()
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('zip extension missing');
        }

        $this->setPrivateProperty('isWindows', true);
        $this->setPrivateProperty('hasSystemUnzip', true);
        $this->setPrivateProperty('hasZipArchive', true);

        $processExecutor = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();
        $processExecutor->expects($this->atLeastOnce())
          ->method('execute')
          ->will($this->returnValue(1));

        $zipArchive = $this->getMockBuilder('ZipArchive')->getMock();
        $zipArchive->expects($this->at(0))
          ->method('open')
          ->will($this->returnValue(true));
        $zipArchive->expects($this->at(1))
          ->method('extractTo')
          ->will($this->returnValue(false));

        $downloader = new MockedZipDownloader($this->io, $this->config, $this->httpDownloader, null, null, $processExecutor);
        $this->setPrivateProperty('zipArchiveObject', $zipArchive, $downloader);
        $downloader->extract($this->package, 'testfile.zip', 'vendor/dir');
    }
}

class MockedZipDownloader extends ZipDownloader
{
    public function download(PackageInterface $package, $path, $output = true)
    {
        return;
    }

    public function install(PackageInterface $package, $path, $output = true)
    {
        return;
    }

    public function extract(PackageInterface $package, $file, $path)
    {
        parent::extract($package, $file, $path);
    }
}
