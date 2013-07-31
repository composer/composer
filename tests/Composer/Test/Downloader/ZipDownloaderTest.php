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

class ZipDownloaderTest extends \PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('zip extension missing');
        }
    }

    public function testErrorMessages()
    {
        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->any())
            ->method('getDistUrl')
            ->will($this->returnValue('file://'.__FILE__))
        ;

        $io = $this->getMock('Composer\IO\IOInterface');
        $config = $this->getMock('Composer\Config');
        $config->expects($this->any())
            ->method('get')
            ->with('vendor-dir')
            ->will($this->returnValue(sys_get_temp_dir().'/composer-zip-test-vendor'));
        $downloader = new ZipDownloader($io, $config);

        try {
            $downloader->download($packageMock, sys_get_temp_dir().'/composer-zip-test');
            $this->fail('Download of invalid zip files should throw an exception');
        } catch (\UnexpectedValueException $e) {
            $this->assertContains('is not a zip archive', $e->getMessage());
        }
    }
}
