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
use Composer\TestCase;
use Composer\Util\Filesystem;

class ZipDownloaderTest extends TestCase
{
    /**
     * @var string
     */
    private $testDir;

    public function setUp()
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('zip extension missing');
        }

        $this->testDir = $this->getUniqueTmpDirectory();
    }

    public function tearDown()
    {
        $fs = new Filesystem;
        $fs->removeDirectory($this->testDir);
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
        $config->expects($this->at(0))
            ->method('get')
            ->with('disable-tls')
            ->will($this->returnValue(false));
        $config->expects($this->at(1))
            ->method('get')
            ->with('cafile')
            ->will($this->returnValue(null));
        $config->expects($this->at(2))
            ->method('get')
            ->with('capath')
            ->will($this->returnValue(null));
        $config->expects($this->at(3))
            ->method('get')
            ->with('vendor-dir')
            ->will($this->returnValue($this->testDir));
        $downloader = new ZipDownloader($io, $config);

        try {
            $downloader->download($packageMock, sys_get_temp_dir().'/composer-zip-test');
            $this->fail('Download of invalid zip files should throw an exception');
        } catch (\UnexpectedValueException $e) {
            $this->assertContains('is not a zip archive', $e->getMessage());
        }
    }
}
