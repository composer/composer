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

namespace Composer\Test\Installer;

use Composer\Installer\AssetInstaller;
use Composer\DependencyResolver\Operation;
use Composer\Util\Filesystem;
use Composer\Test\TestCase;

/**
 * Asset installation manager test.
 *
 * @author Mike van Riel <mike.vanriel@naenius.com>
 *
 * @see Composer\Installer\AssetInstaller for a description of the business
 *     logic in the class description.
 */
class AssetInstallerTest extends TestCase
{
    private $vendorDir;
    private $binDir;
    private $dm;
    private $repository;
    private $io;
    private $fs;

    protected function setUp()
    {
        $this->fs = new Filesystem;

        $this->vendorDir = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'composer-test-vendor';
        $this->ensureDirectoryExistsAndClear($this->vendorDir);

        $this->binDir = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'composer-test-bin';
        $this->ensureDirectoryExistsAndClear($this->binDir);

        $this->dm = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->disableOriginalConstructor()
            ->getMock();

        $this->repository = $this->getMockBuilder('Composer\Repository\WritableRepositoryInterface')
            ->getMock();

        $this->io = $this->getMockBuilder('Composer\IO\IOInterface')
            ->getMock();
    }

    protected function tearDown()
    {
        $this->fs->removeDirectory($this->vendorDir);
        $this->fs->removeDirectory($this->binDir);
    }

    public function testGetInstallPath()
    {
        $asset_dir = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'assets';
        $extra = array('asset-dir' => $asset_dir);

        $asset = new AssetInstaller($this->vendorDir, $this->binDir, $this->dm, $this->repository, $this->io);
        $package = $this->createPackageMock();

        $package
            ->expects($this->exactly(2))
            ->method('getTargetDir')
            ->will($this->returnValue(null));

        $package_with_asset_dir = clone $package;
        $package_with_asset_dir
            ->expects($this->once())
            ->method('getExtra')
            ->will($this->returnValue($extra));

        // if no asset-dir is provided; continue providing default behaviour
        $this->assertEquals($this->vendorDir.'/'.$package->getName(), $asset->getInstallPath($package));

        // if asset-dir is provided; replace vendor-dir+packagename with asset-dir
        $this->assertEquals($asset_dir, $asset->getInstallPath($package_with_asset_dir));
    }

    public function testGetInstallPathWithTargetDir()
    {
        $asset_dir = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR .'assets';
        $extra = array('asset-dir' => $asset_dir);

        $asset = new AssetInstaller($this->vendorDir, $this->binDir, $this->dm, $this->repository, $this->io);
        $package = $this->createPackageMock();

        $package
            ->expects($this->exactly(2))
            ->method('getTargetDir')
            ->will($this->returnValue('Some/Namespace'));
        $package
            ->expects($this->any())
            ->method('getPrettyName')
            ->will($this->returnValue('foo/bar'));

        $package_with_asset_dir = clone $package;
        $package_with_asset_dir
            ->expects($this->once())
            ->method('getExtra')
            ->will($this->returnValue($extra));

        // if no asset-dir is provided; continue providing default behaviour
        $this->assertEquals($this->vendorDir.'/'.$package->getPrettyName().'/Some/Namespace', $asset->getInstallPath($package));

        // if asset-dir is provided; replace vendor-dir+packagename with asset-dir
        $this->assertEquals($asset_dir . '/Some/Namespace', $asset->getInstallPath($package_with_asset_dir));
    }

    private function createPackageMock()
    {
        return $this->getMockBuilder('Composer\Package\MemoryPackage')
            ->setConstructorArgs(array(md5(rand()), '1.0.0.0', '1.0.0'))
            ->getMock();
    }
}
