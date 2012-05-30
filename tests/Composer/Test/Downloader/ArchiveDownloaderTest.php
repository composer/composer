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

use Composer\Util\Filesystem;

class ArchiveDownloaderTest extends \PHPUnit_Framework_TestCase
{
    public function testGetFileName()
    {
        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->any())
            ->method('getDistUrl')
            ->will($this->returnValue('http://example.com/script.js'))
        ;

        $downloader = $this->getMockForAbstractClass('Composer\Downloader\ArchiveDownloader', array($this->getMock('Composer\IO\IOInterface')));
        $method = new \ReflectionMethod($downloader, 'getFileName');
        $method->setAccessible(true);

        $first = $method->invoke($downloader, $packageMock, '/path');
        $this->assertRegExp('#/path/[a-z0-9]+\.js#', $first);
        $this->assertSame($first, $method->invoke($downloader, $packageMock, '/path'));
    }

    public function testProcessUrl()
    {
        $downloader = $this->getMockForAbstractClass('Composer\Downloader\ArchiveDownloader', array($this->getMock('Composer\IO\IOInterface')));
        $method = new \ReflectionMethod($downloader, 'processUrl');
        $method->setAccessible(true);

        $expected = 'https://github.com/composer/composer/zipball/master';
        $url = $method->invoke($downloader, $expected);

        if (extension_loaded('openssl')) {
            $this->assertEquals($expected, $url);
        } else {
            $this->assertEquals('http://nodeload.github.com/composer/composer/zipball/master', $url);
        }
    }

    public function testExtractShouldUnwrapOnlyDir()
    {
        $downloader = $this->getMockForAbstractClass('Composer\Downloader\ArchiveDownloader', array($this->getMock('Composer\IO\IOInterface')));
        $method = new \ReflectionMethod($downloader, 'extract');
        $method->setAccessible(true);

        $filesystem = new Filesystem();
        $path = sys_get_temp_dir().'/composer'.md5(time());

        try {
            $filesystem->ensureDirectoryExists($path);

            // prepare dir with one dir and one archive file
            $file = $path . '/somePackage.tgz';
            touch($file);
            $packagePath = $path . '/somePackage-1.1.1';
            $filesystem->ensureDirectoryExists($packagePath);
            $extractedFile = $packagePath . '/packageFile.php';
            touch($extractedFile);

            $method->invoke($downloader, $file, $path);

            $this->assertFileExists($path . '/packageFile.php');
            $this->assertFileNotExists($path . '/somePackage-1.1.1');
            $this->assertFileExists($path . '/somePackage.tgz');
        } catch (\Exception $e) {
            // cleanup
            $filesystem->removeDirectory($path);

            throw $e;
        }
        // cleanup
        $filesystem->removeDirectory($path);
    }

    public function testExtractShouldNotUnwrapSeveralDirs()
    {
        $downloader = $this->getMockForAbstractClass('Composer\Downloader\ArchiveDownloader', array($this->getMock('Composer\IO\IOInterface')));
        $method = new \ReflectionMethod($downloader, 'extract');
        $method->setAccessible(true);

        $filesystem = new Filesystem();
        $path = sys_get_temp_dir().'/composer'.md5(time());

        try {
            $filesystem->ensureDirectoryExists($path);

            // prepare dir with one dir and one archive file
            $file = $path . '/somePackage.tgz';
            touch($file);
            $packagePath1 = $path . '/somePackage-1.1.1';
            $filesystem->ensureDirectoryExists($packagePath1);
            $packagePath2 = $path . '/somePackage-1.1.2';
            $filesystem->ensureDirectoryExists($packagePath2);
            $extractedFile = $packagePath1 . '/packageFile.php';
            touch($extractedFile);

            $method->invoke($downloader, $file, $path);

            $this->assertFileExists($packagePath1);
            $this->assertFileExists($packagePath2);
            $this->assertFileExists($extractedFile);
        } catch (\Exception $e) {
            // cleanup
            $filesystem->removeDirectory($path);

            throw $e;
        }
        // cleanup
        $filesystem->removeDirectory($path);
    }
}
