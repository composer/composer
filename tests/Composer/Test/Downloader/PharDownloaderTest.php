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
use Composer\Downloader\PharDownloader;

class PharDownloaderTest extends \PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        if (!class_exists('Phar')) {
            $this->markTestSkipped('zip extension missing');
        }
    }

    /**
     * @dataProvider canExtractProvider
     */
    public function testCanExtract($extract)
    {
        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->any())
            ->method('getDistExtract')
            ->will($this->returnValue($extract))
        ;
        
        $downloader = $this->getMockForAbstractClass('Composer\Downloader\PharDownloader', array($this->getMock('Composer\IO\IOInterface')));
        $method = new \ReflectionMethod($downloader, 'canExtract');
        $method->setAccessible(true);
        
        $canExtract = $method->invoke($downloader, $packageMock);
        $this->assertInternalType('boolean', $canExtract);
        $this->assertEquals($extract, $canExtract);
    }
    
    public function canExtractProvider()
    {
        return array(
            array(true),
            array(false),
        );
    }
    
    public function testGetFileNameWithExtract()
    {
        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->any())
            ->method('getDistExtract')
            ->will($this->returnValue(true))
        ;
        $packageMock->expects($this->any())
            ->method('getDistUrl')
            ->will($this->returnValue('http://example.com/app.phar'))
        ;

        $downloader = $this->getMockForAbstractClass('Composer\Downloader\PharDownloader', array($this->getMock('Composer\IO\IOInterface')));
        $method = new \ReflectionMethod($downloader, 'getFileName');
        $method->setAccessible(true);

        $first = $method->invoke($downloader, $packageMock, '/path');
        $this->assertRegExp('#/path/[a-z0-9]+\.phar#', $first);
        $this->assertSame($first, $method->invoke($downloader, $packageMock, '/path'));
        $this->assertNotSame('/path/app.phar', $method->invoke($downloader, $packageMock, '/path'));
    }
    
    public function testGetFileNameWithoutExtract()
    {
        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->any())
            ->method('getDistExtract')
            ->will($this->returnValue(false))
        ;
        $packageMock->expects($this->any())
            ->method('getDistUrl')
            ->will($this->returnValue('http://example.com/app.phar'))
        ;

        $downloader = $this->getMockForAbstractClass('Composer\Downloader\PharDownloader', array($this->getMock('Composer\IO\IOInterface')));
        $method = new \ReflectionMethod($downloader, 'getFileName');
        $method->setAccessible(true);

        $this->assertSame('/path/app.phar', $method->invoke($downloader, $packageMock, '/path'));
    }
}
