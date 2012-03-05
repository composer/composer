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

use Composer\Downloader\FileDownloader;
use Composer\Util\Filesystem;

class FileDownloaderTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @expectedException \InvalidArgumentException
     */
    public function testDownloadForPackageWithoutDistReference()
    {
        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->once())
            ->method('getDistUrl')
            ->will($this->returnValue(null))
        ;

        $downloader = new FileDownloader($this->getMock('Composer\IO\IOInterface'));
        $downloader->download($packageMock, '/path');
    }

    public function testDownloadToExistFile()
    {
        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->once())
            ->method('getDistUrl')
            ->will($this->returnValue('url'))
        ;

        $path = tempnam(sys_get_temp_dir(), 'c');

        $downloader = new FileDownloader($this->getMock('Composer\IO\IOInterface'));
        try {
            $downloader->download($packageMock, $path);
            $this->fail();
        } catch (\Exception $e) {
            if (file_exists($path)) {
                unset($path);
            }
            $this->assertInstanceOf('UnexpectedValueException', $e);
            $this->assertContains('exists and is not a directory', $e->getMessage());
        }
    }

    public function testGetFileName()
    {
        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->once())
            ->method('getDistUrl')
            ->will($this->returnValue('http://example.com/script.js'))
        ;

        $downloader = new FileDownloader($this->getMock('Composer\IO\IOInterface'));
        $method = new \ReflectionMethod($downloader, 'getFileName');
        $method->setAccessible(true);

        $this->assertEquals('/path/script.js', $method->invoke($downloader, $packageMock, '/path'));
    }

    public function testDownloadButFileIsUnsaved()
    {
        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->any())
            ->method('getDistUrl')
            ->will($this->returnValue('http://example.com/script.js'))
        ;

        do {
            $path = sys_get_temp_dir().'/'.md5(time().rand());
        } while (file_exists($path));

        $ioMock = $this->getMock('Composer\IO\IOInterface');
        $ioMock->expects($this->any())
            ->method('write')
            ->will($this->returnCallback(function($messages, $newline = true) use ($path) {
                if (is_file($path.'/script.js')) {
                    unlink($path.'/script.js');
                }
                return $messages;
            }))
        ;

        $downloader = new FileDownloader($ioMock);
        try {
            $downloader->download($packageMock, $path);
            $this->fail();
        } catch (\Exception $e) {
            if (is_dir($path)) {
                $fs = new Filesystem();
                $fs->removeDirectory($path);
            } else if (is_file($path)) {
                unset($path);
            }

            $this->assertInstanceOf('UnexpectedValueException', $e);
            $this->assertContains('could not be saved to', $e->getMessage());
        }
    }

    public function testDownloadFileWithInvalidChecksum()
    {
        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->any())
            ->method('getDistUrl')
            ->will($this->returnValue('http://example.com/script.js'))
        ;
        $packageMock->expects($this->any())
            ->method('getDistSha1Checksum')
            ->will($this->returnValue('invalid'))
        ;

        do {
            $path = sys_get_temp_dir().'/'.md5(time().rand());
        } while (file_exists($path));

        $downloader = new FileDownloader($this->getMock('Composer\IO\IOInterface'));
        try {
            $downloader->download($packageMock, $path);
            $this->fail();
        } catch (\Exception $e) {
            if (is_dir($path)) {
                $fs = new Filesystem();
                $fs->removeDirectory($path);
            } else if (is_file($path)) {
                unset($path);
            }

            $this->assertInstanceOf('UnexpectedValueException', $e);
            $this->assertContains('checksum verification', $e->getMessage());
        }
    }
}
