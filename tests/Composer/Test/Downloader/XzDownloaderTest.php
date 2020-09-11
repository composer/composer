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
use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Composer\Util\Platform;
use Composer\Util\Loop;
use Composer\Util\HttpDownloader;

class XzDownloaderTest extends TestCase
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
        if (Platform::isWindows()) {
            $this->markTestSkipped('Skip test on Windows');
        }
        $this->testDir = $this->getUniqueTmpDirectory();
    }

    public function tearDown()
    {
        $this->fs = new Filesystem;
        $this->fs->removeDirectory($this->testDir);
    }

    public function testErrorMessages()
    {
        $packageMock = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
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

        $io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
        $config = $this->getMockBuilder('Composer\Config')->getMock();
        $config->expects($this->any())
            ->method('get')
            ->with('vendor-dir')
            ->will($this->returnValue($this->testDir));
        $downloader = new XzDownloader($io, $config, $httpDownloader = new HttpDownloader($io, $this->getMockBuilder('Composer\Config')->getMock()), null, null, null);

        try {
            $loop = new Loop($httpDownloader);
            $promise = $downloader->download($packageMock, $this->testDir.'/install-path');
            $loop->wait(array($promise));
            $downloader->install($packageMock, $this->testDir.'/install-path');

            $this->fail('Download of invalid tarball should throw an exception');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/(File format not recognized|Unrecognized archive format)/i', $e->getMessage());
        }
    }
}
