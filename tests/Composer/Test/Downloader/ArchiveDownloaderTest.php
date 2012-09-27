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
        $url = $method->invoke($downloader, $this->getMock('Composer\Package\PackageInterface'), $expected);

        if (extension_loaded('openssl')) {
            $this->assertEquals($expected, $url);
        } else {
            $this->assertEquals('http://nodeload.github.com/composer/composer/zipball/master', $url);
        }
    }
}
