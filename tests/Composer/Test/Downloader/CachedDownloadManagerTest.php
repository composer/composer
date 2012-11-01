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

use Composer\Downloader\CachedDownloadManager;

class CachedDownloadManagerTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var CachedDownloadManager
     */
    private $manager;
    /**
     * @var \Composer\Storage\StorageInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    private $storage;
    /**
     * @var \Composer\Package\PackageInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    private $package;
    /**
     * @var \Composer\Downloader\DownloaderInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    private $downloader;

    protected function setUp()
    {
        $this->storage = $this->getMock('Composer\Storage\StorageInterface');

        $this->package = $this->getMock('Composer\Package\PackageInterface');
        $this->package
            ->expects($this->any())
            ->method('getDistType')
            ->will($this->returnValue('download'));
        $this->package
            ->expects($this->any())
            ->method('getSourceType')
            ->will($this->returnValue('download'));


        $this->downloader = $this->getMock('Composer\Downloader\DownloaderInterface');

        $filesystem = $this->getMock('Composer\Util\Filesystem');

        $this->manager = new CachedDownloadManager($this->storage, false, $filesystem);
        $this->manager->setDownloader('download', $this->downloader);
    }

    public function testCacheOnlyDist()
    {
        $this->storage
            ->expects($this->never())
            ->method('storePackage');

        $this->package
            ->expects($this->any())
            ->method('getInstallationSource')
            ->will($this->returnValue('source'));

        $this->downloader
            ->expects($this->any())
            ->method('getInstallationSource')
            ->will($this->returnValue('source'));

        $this->manager->download($this->package, '/foo/bar', true);
    }

    public function testNoCacheIfExists()
    {
        $this->storage
            ->expects($this->never())
            ->method('storePackage');

        $this->storage
            ->expects($this->once())
            ->method('hasPackage')
            ->will($this->returnValue(true));

        $this->package
            ->expects($this->any())
            ->method('getInstallationSource')
            ->will($this->returnValue('dist'));

        $this->downloader
            ->expects($this->any())
            ->method('getInstallationSource')
            ->will($this->returnValue('dist'));

        $this->manager->download($this->package, '/foo/bar');
    }

    public function testCacheToStorage()
    {
        $this->storage
            ->expects($this->once())
            ->method('storePackage')
            ->with($this->package, '/foo/bar');

        $this->package
            ->expects($this->any())
            ->method('getInstallationSource')
            ->will($this->returnValue('dist'));

        $this->downloader
            ->expects($this->any())
            ->method('getInstallationSource')
            ->will($this->returnValue('dist'));

        $this->manager->download($this->package, '/foo/bar');
    }
}
