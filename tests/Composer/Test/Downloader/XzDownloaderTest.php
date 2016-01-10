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

use Composer\Downloader\XzDownloader;
use Composer\Util\Filesystem;

class XzDownloaderTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Filesystem
     */
    private $fs;

    /**
     * @var string
     */
    private $testDir;

    public function setUp()
    {
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            $this->markTestSkipped('Skip test on Windows');
        }
        $this->testDir = sys_get_temp_dir().'/composer-xz-test-vendor';
    }

    public function tearDown()
    {
        $this->fs = new Filesystem;
        $this->fs->removeDirectory($this->testDir);
    }

    public function testErrorMessages()
    {
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

        $io = $this->getMock('Composer\IO\IOInterface');
        $config = $this->getMock('Composer\Config');
        $config->expects($this->any())
            ->method('get')
            ->with('vendor-dir')
            ->will($this->returnValue($this->testDir));
        $config->expects($this->any())
            ->method('get')
            ->with('disable-tls')
            ->will($this->returnValue(false));
        $downloader = new XzDownloader($io, $config);

        try {
            $downloader->download($packageMock, sys_get_temp_dir().'/composer-xz-test');
            $this->fail('Download of invalid tarball should throw an exception');
        } catch (\RuntimeException $e) {
            $this->assertContains('File format not recognized', $e->getMessage());
        }
    }
}
