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
use Composer\Downloader\PearDownloader;

class PearDownloaderTest extends \PHPUnit_Framework_TestCase
{
    public function testErrorMessages()
    {
        $packageMock = $this->getMock('Composer\Package\PackageInterface');
        $packageMock->expects($this->any())
            ->method('getDistUrl')
            ->will($this->returnValue('file://'.__FILE__))
        ;

        $io = $this->getMock('Composer\IO\IOInterface');
        $downloader = new PearDownloader($io);

        try {
            $downloader->download($packageMock, sys_get_temp_dir().'/composer-pear-test');
            $this->fail('Download of invalid pear packages should throw an exception');
        } catch (\UnexpectedValueException $e) {
            $this->assertContains('internal corruption of phar ', $e->getMessage());
        }
    }

}
