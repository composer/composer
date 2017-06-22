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
use Composer\TestCase;
use Composer\Util\Filesystem;

class ZipDownloaderTest extends TestCase
{
    /**
     * @var string
     */
    private $testDir;
    private $prophet;

    public function setUp()
    {
        $this->testDir = $this->getUniqueTmpDirectory();
        $this->io = $this->getMock('Composer\IO\IOInterface');
        $this->config = $this->getMock('Composer\Config');
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
            $reflectedProperty = $reflectedProperty->setValue($value);
        } else {
            $reflectedProperty = $reflectedProperty->setValue($obj, $value);
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
            ->with('disable-tls')
            ->will($this->returnValue(false));
        $this->config->expects($this->at(1))
            ->method('get')
            ->with('cafile')
            ->will($this->returnValue(null));
        $this->config->expects($this->at(2))
            ->method('get')
            ->with('capath')
            ->will($this->returnValue(null));
        $this->config->expects($this->at(3))
            ->method('get')
            ->with('vendor-dir')
            ->will($this->returnValue($this->testDir));

        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->any())
            ->method('getDistUrl')
            ->will($this->returnValue($distUrl = 'file://'.__FILE__))
        ;
        $packageMock->expects($this->any())
            ->method('getDistUrls')
            ->will($this->returnValue(array($distUrl)))
        ;
        $packageMock->expects($this->atLeastOnce())
            ->method('getTransportOptions')
            ->will($this->returnValue(array()))
        ;

        $downloader = new ZipDownloader($this->io, $this->config);

        $this->setPrivateProperty('hasSystemUnzip', false);

        try {
            $downloader->download($packageMock, sys_get_temp_dir().'/composer-zip-test');
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
        $this->setPrivateProperty('hasSystemUnzip', false);
        $this->setPrivateProperty('hasZipArchive', true);
        $downloader = new MockedZipDownloader($this->io, $this->config);

        $zipArchive = $this->getMock('ZipArchive');
        $zipArchive->expects($this->at(0))
            ->method('open')
            ->will($this->returnValue(true));
        $zipArchive->expects($this->at(1))
            ->method('extractTo')
            ->will($this->returnValue(false));

        $this->setPrivateProperty('zipArchiveObject', $zipArchive, $downloader);
        $downloader->extract('testfile.zip', 'vendor/dir');
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage The archive may contain identical file names with different capitalization (which fails on case insensitive filesystems): Not a directory
     */
    public function testZipArchiveExtractOnlyFailed()
    {
        $this->setPrivateProperty('hasSystemUnzip', false);
        $this->setPrivateProperty('hasZipArchive', true);
        $downloader = new MockedZipDownloader($this->io, $this->config);

        $zipArchive = $this->getMock('ZipArchive');
        $zipArchive->expects($this->at(0))
            ->method('open')
            ->will($this->returnValue(true));
        $zipArchive->expects($this->at(1))
            ->method('extractTo')
            ->will($this->throwException(new \ErrorException('Not a directory')));

        $this->setPrivateProperty('zipArchiveObject', $zipArchive, $downloader);
        $downloader->extract('testfile.zip', 'vendor/dir');
    }

    /**
     * @group only
     */
    public function testZipArchiveOnlyGood()
    {
        $this->setPrivateProperty('hasSystemUnzip', false);
        $this->setPrivateProperty('hasZipArchive', true);
        $downloader = new MockedZipDownloader($this->io, $this->config);

        $zipArchive = $this->getMock('ZipArchive');
        $zipArchive->expects($this->at(0))
            ->method('open')
            ->will($this->returnValue(true));
        $zipArchive->expects($this->at(1))
            ->method('extractTo')
            ->will($this->returnValue(true));

        $this->setPrivateProperty('zipArchiveObject', $zipArchive, $downloader);
        $downloader->extract('testfile.zip', 'vendor/dir');
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Failed to execute unzip
     */
    public function testSystemUnzipOnlyFailed()
    {
        $this->setPrivateProperty('hasSystemUnzip', true);
        $this->setPrivateProperty('hasZipArchive', false);
        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');
        $processExecutor->expects($this->at(0))
            ->method('execute')
            ->will($this->returnValue(1));

        $downloader = new MockedZipDownloader($this->io, $this->config, null, null, $processExecutor);
        $downloader->extract('testfile.zip', 'vendor/dir');
    }

    public function testSystemUnzipOnlyGood()
    {
        $this->setPrivateProperty('hasSystemUnzip', true);
        $this->setPrivateProperty('hasZipArchive', false);
        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');
        $processExecutor->expects($this->at(0))
            ->method('execute')
            ->will($this->returnValue(0));

        $downloader = new MockedZipDownloader($this->io, $this->config, null, null, $processExecutor);
        $downloader->extract('testfile.zip', 'vendor/dir');
    }

    public function testNonWindowsFallbackGood()
    {
        $this->setPrivateProperty('isWindows', false);
        $this->setPrivateProperty('hasSystemUnzip', true);
        $this->setPrivateProperty('hasZipArchive', true);

        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');
        $processExecutor->expects($this->at(0))
            ->method('execute')
            ->will($this->returnValue(1));

        $zipArchive = $this->getMock('ZipArchive');
        $zipArchive->expects($this->at(0))
            ->method('open')
            ->will($this->returnValue(true));
        $zipArchive->expects($this->at(1))
            ->method('extractTo')
            ->will($this->returnValue(true));

        $downloader = new MockedZipDownloader($this->io, $this->config, null, null, $processExecutor);
        $this->setPrivateProperty('zipArchiveObject', $zipArchive, $downloader);
        $downloader->extract('testfile.zip', 'vendor/dir');
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage There was an error extracting the ZIP file
     */
    public function testNonWindowsFallbackFailed()
    {
        $this->setPrivateProperty('isWindows', false);
        $this->setPrivateProperty('hasSystemUnzip', true);
        $this->setPrivateProperty('hasZipArchive', true);

        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');
        $processExecutor->expects($this->at(0))
          ->method('execute')
          ->will($this->returnValue(1));

        $zipArchive = $this->getMock('ZipArchive');
        $zipArchive->expects($this->at(0))
          ->method('open')
          ->will($this->returnValue(true));
        $zipArchive->expects($this->at(1))
          ->method('extractTo')
          ->will($this->returnValue(false));

        $downloader = new MockedZipDownloader($this->io, $this->config, null, null, $processExecutor);
        $this->setPrivateProperty('zipArchiveObject', $zipArchive, $downloader);
        $downloader->extract('testfile.zip', 'vendor/dir');
    }

    public function testWindowsFallbackGood()
    {
        $this->setPrivateProperty('isWindows', true);
        $this->setPrivateProperty('hasSystemUnzip', true);
        $this->setPrivateProperty('hasZipArchive', true);

        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');
        $processExecutor->expects($this->atLeastOnce())
            ->method('execute')
            ->will($this->returnValue(0));

        $zipArchive = $this->getMock('ZipArchive');
        $zipArchive->expects($this->at(0))
            ->method('open')
            ->will($this->returnValue(true));
        $zipArchive->expects($this->at(1))
            ->method('extractTo')
            ->will($this->returnValue(false));

        $downloader = new MockedZipDownloader($this->io, $this->config, null, null, $processExecutor);
        $this->setPrivateProperty('zipArchiveObject', $zipArchive, $downloader);
        $downloader->extract('testfile.zip', 'vendor/dir');
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Failed to execute unzip
     */
    public function testWindowsFallbackFailed()
    {
        $this->setPrivateProperty('isWindows', true);
        $this->setPrivateProperty('hasSystemUnzip', true);
        $this->setPrivateProperty('hasZipArchive', true);

        $processExecutor = $this->getMock('Composer\Util\ProcessExecutor');
        $processExecutor->expects($this->atLeastOnce())
          ->method('execute')
          ->will($this->returnValue(1));

        $zipArchive = $this->getMock('ZipArchive');
        $zipArchive->expects($this->at(0))
          ->method('open')
          ->will($this->returnValue(true));
        $zipArchive->expects($this->at(1))
          ->method('extractTo')
          ->will($this->returnValue(false));

        $downloader = new MockedZipDownloader($this->io, $this->config, null, null, $processExecutor);
        $this->setPrivateProperty('zipArchiveObject', $zipArchive, $downloader);
        $downloader->extract('testfile.zip', 'vendor/dir');
    }
}

class MockedZipDownloader extends ZipDownloader
{
    public function download(PackageInterface $package, $path, $output = true)
    {
        return;
    }

    public function extract($file, $path)
    {
        parent::extract($file, $path);
    }
}
