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

class ArchiveDownloaderTest extends \PHPUnit_Framework_TestCase
{
    public function testGetFileName()
    {
        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->any())
            ->method('getDistUrl')
            ->will($this->returnValue('http://example.com/script.js'))
        ;

        $downloader = $this->getMockForAbstractClass('Composer\Downloader\ArchiveDownloader', array($this->getMock('Composer\IO\IOInterface'), $this->getMock('Composer\Config')));
        $method = new \ReflectionMethod($downloader, 'getFileName');
        $method->setAccessible(true);

        $first = $method->invoke($downloader, $packageMock, '/path');
        $this->assertRegExp('#/path/[a-z0-9]+\.js#', $first);
        $this->assertSame($first, $method->invoke($downloader, $packageMock, '/path'));
    }

    public function testProcessUrl()
    {
        $downloader = $this->getMockForAbstractClass('Composer\Downloader\ArchiveDownloader', array($this->getMock('Composer\IO\IOInterface'), $this->getMock('Composer\Config')));
        $method = new \ReflectionMethod($downloader, 'processUrl');
        $method->setAccessible(true);

        $expected = 'https://github.com/composer/composer/zipball/master';
        $url = $method->invoke($downloader, $this->getMock('Composer\Package\PackageInterface'), $expected);

        if (extension_loaded('openssl')) {
            $this->assertEquals($expected, $url);
        } else {
            $this->assertEquals('http://nodeload.github.com/composer/composer/zip/master', $url);
        }
    }

    public function testProcessUrl2()
    {
        $downloader = $this->getMockForAbstractClass('Composer\Downloader\ArchiveDownloader', array($this->getMock('Composer\IO\IOInterface'), $this->getMock('Composer\Config')));
        $method = new \ReflectionMethod($downloader, 'processUrl');
        $method->setAccessible(true);

        $expected = 'https://github.com/composer/composer/archive/master.tar.gz';
        $url = $method->invoke($downloader, $this->getMock('Composer\Package\PackageInterface'), $expected);

        if (extension_loaded('openssl')) {
            $this->assertEquals($expected, $url);
        } else {
            $this->assertEquals('http://nodeload.github.com/composer/composer/tar.gz/master', $url);
        }
    }

    public function testProcessUrl3()
    {
        $downloader = $this->getMockForAbstractClass('Composer\Downloader\ArchiveDownloader', array($this->getMock('Composer\IO\IOInterface'), $this->getMock('Composer\Config')));
        $method = new \ReflectionMethod($downloader, 'processUrl');
        $method->setAccessible(true);

        $expected = 'https://api.github.com/repos/composer/composer/zipball/master';
        $url = $method->invoke($downloader, $this->getMock('Composer\Package\PackageInterface'), $expected);

        if (extension_loaded('openssl')) {
            $this->assertEquals($expected, $url);
        } else {
            $this->assertEquals('http://nodeload.github.com/composer/composer/zip/master', $url);
        }
    }

    /**
     * @dataProvider provideUrls
     */
    public function testProcessUrlRewriteDist($url)
    {
        $downloader = $this->getMockForAbstractClass('Composer\Downloader\ArchiveDownloader', array($this->getMock('Composer\IO\IOInterface'), $this->getMock('Composer\Config')));
        $method = new \ReflectionMethod($downloader, 'processUrl');
        $method->setAccessible(true);

        $type = strpos($url, 'tar') ? 'tar' : 'zip';
        $expected = 'https://api.github.com/repos/composer/composer/'.$type.'ball/ref';

        $package = $this->getMock('Composer\Package\PackageInterface');
        $package->expects($this->any())
            ->method('getDistReference')
            ->will($this->returnValue('ref'));
        $url = $method->invoke($downloader, $package, $url);

        if (extension_loaded('openssl')) {
            $this->assertEquals($expected, $url);
        } else {
            $this->assertEquals('http://nodeload.github.com/composer/composer/'.$type.'/ref', $url);
        }
    }

    public function provideUrls()
    {
        return array(
            array('https://api.github.com/repos/composer/composer/zipball/master'),
            array('https://api.github.com/repos/composer/composer/tarball/master'),
            array('https://github.com/composer/composer/zipball/master'),
            array('https://www.github.com/composer/composer/tarball/master'),
            array('https://github.com/composer/composer/archive/master.zip'),
            array('https://github.com/composer/composer/archive/master.tar.gz'),
        );
    }
}
