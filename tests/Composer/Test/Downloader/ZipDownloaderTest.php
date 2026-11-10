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
use Composer\Util\Platform;

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

    public function setUp()
    {
        $this->testDir = $this->getUniqueTmpDirectory();
        $this->io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $this->config = $this->getMockBuilder('Composer\Config')->getMock();
        $dlConfig = $this->getMockBuilder('Composer\Config')->getMock();
        $this->httpDownloader = new HttpDownloader($this->io, $dlConfig);
        $this->package = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();

        $this->filename = $this->testDir.'/composer-test.zip';
        file_put_contents($this->filename, 'zip');
    }

    public function tearDown()
    {
        $fs = new Filesystem;
        $fs->removeDirectory($this->testDir);
        $this->setPrivateProperty('hasZipArchive', null);
    }

    /**
     * @param string $name
     * @param mixed $value
     * @param ?\Composer\Test\Downloader\MockedZipDownloader $obj
     * @return void
     */
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

    public function testExtractWithZipArchiveDoesNotWriteThroughPreexistingSymlinks()
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('zip extension missing');
        }
        if (Platform::isWindows()) {
            $this->markTestSkipped('symlink creation requires privileges on Windows');
        }

        $fs = new Filesystem;
        $extractDir = $this->testDir.'/extract';
        $fs->ensureDirectoryExists($extractDir);

        // a file outside the extraction directory that must stay untouched
        $outside = $this->testDir.'/outside.txt';
        file_put_contents($outside, 'original');

        // mimic what the unzip command does with a symlink entry: a real symlink inside the
        // extraction directory pointing at the outside file, left behind before the fallback runs
        if (false === @symlink('../outside.txt', $extractDir.'/payload.txt')) {
            $this->markTestSkipped('could not create symlink');
        }

        // a real archive whose regular entry has the same name as the planted symlink
        $zipFile = $this->testDir.'/evil.zip';
        $zip = new \ZipArchive();
        $zip->open($zipFile, \ZipArchive::CREATE);
        $zip->addFromString('payload.txt', 'OVERWRITTEN');
        $zip->close();

        $downloader = new ZipDownloader($this->io, $this->config, $this->httpDownloader);
        $downloader->extractWithZipArchive($this->package, $zipFile, $extractDir);

        $this->assertSame('original', file_get_contents($outside), 'the file outside the extraction directory must not be written through the symlink');
        $this->assertFalse(is_link($extractDir.'/payload.txt'), 'the pre-existing symlink must be removed before extraction');
        $this->assertSame('OVERWRITTEN', file_get_contents($extractDir.'/payload.txt'));
    }

    public function testExtractWithZipArchiveDoesNotWriteThroughPreexistingDirectorySymlink()
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('zip extension missing');
        }
        if (Platform::isWindows()) {
            $this->markTestSkipped('symlink creation requires privileges on Windows');
        }

        $fs = new Filesystem;
        $extractDir = $this->testDir.'/extract';
        $fs->ensureDirectoryExists($extractDir);

        // a directory outside the extraction directory that must stay empty
        $outsideDir = $this->testDir.'/outside';
        $fs->ensureDirectoryExists($outsideDir);

        // mimic a symlink entry pointing at an outside directory, left behind before the fallback runs
        if (false === @symlink('../outside', $extractDir.'/sub')) {
            $this->markTestSkipped('could not create symlink');
        }

        // a real archive whose entry would be written into the planted directory symlink
        $zipFile = $this->testDir.'/evil.zip';
        $zip = new \ZipArchive();
        $zip->open($zipFile, \ZipArchive::CREATE);
        $zip->addFromString('sub/evil.txt', 'OWNED');
        $zip->close();

        $downloader = new ZipDownloader($this->io, $this->config, $this->httpDownloader);
        $downloader->extractWithZipArchive($this->package, $zipFile, $extractDir);

        $this->assertFalse(file_exists($outsideDir.'/evil.txt'), 'nothing must be written through the directory symlink to outside the extraction directory');
        $this->assertFalse(is_link($extractDir.'/sub'), 'the pre-existing directory symlink must be removed before extraction');
        $this->assertSame('OWNED', file_get_contents($extractDir.'/sub/evil.txt'));
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
        $promise = $downloader->extract($this->package, $this->filename, 'vendor/dir');
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
        $promise = $downloader->extract($this->package, $this->filename, 'vendor/dir');
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
        $promise = $downloader->extract($this->package, $this->filename, 'vendor/dir');
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
        $promise = $downloader->extract($this->package, $this->filename, 'vendor/dir');
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
        $promise = $downloader->extract($this->package, $this->filename, 'vendor/dir');
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
        $promise = $downloader->extract($this->package, $this->filename, 'vendor/dir');
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
        $promise = $downloader->extract($this->package, $this->filename, 'vendor/dir');
        $this->wait($promise);
    }

    /**
     * @param ?\React\Promise\PromiseInterface $promise
     * @return void
     */
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
