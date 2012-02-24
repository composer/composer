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

use Composer\Installer\LibraryInstaller;
use Composer\DependencyResolver\Operation;
use Composer\Util\Filesystem;

class LibraryInstallerTest extends \PHPUnit_Framework_TestCase
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
        if (!is_dir($this->vendorDir)) {
            mkdir($this->vendorDir);
        }


        $this->binDir = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'composer-test-bin';
        if (!is_dir($this->binDir)) {
            mkdir($this->binDir);
        }

        $this->dm = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->disableOriginalConstructor()
            ->getMock();

        $this->repository = $this->getMockBuilder('Composer\Repository\WritableRepositoryInterface')
            ->getMock();

        $this->io = $this->getMockBuilder('Composer\IO\IOInterface')
            ->getMock();
    }

    protected function tearDown() {
        if (is_dir($this->vendorDir)) {
            $this->fs->removeDirectory($this->vendorDir);
        }

        if (is_dir($this->binDir)) {
            $this->fs->removeDirectory($this->binDir);
        }
    }

    public function testInstallerCreationShouldNotCreateVendorDirectory()
    {
        $this->fs->removeDirectory($this->vendorDir);

        new LibraryInstaller($this->vendorDir, $this->binDir, $this->dm, $this->repository, $this->io);
        $this->assertFileNotExists($this->vendorDir);
    }

    public function testInstallerCreationShouldNotCreateBinDirectory()
    {
        $this->fs->removeDirectory($this->binDir);

        new LibraryInstaller($this->vendorDir, $this->binDir, $this->dm, $this->repository, $this->io);
        $this->assertFileNotExists($this->binDir);
    }

    public function testIsInstalled()
    {
        $library = new LibraryInstaller($this->vendorDir, $this->binDir, $this->dm, $this->repository, $this->io);
        $package = $this->createPackageMock();

        $this->repository
            ->expects($this->exactly(2))
            ->method('hasPackage')
            ->with($package)
            ->will($this->onConsecutiveCalls(true, false));

        $this->assertTrue($library->isInstalled($package));
        $this->assertFalse($library->isInstalled($package));
    }

    /**
     * @depends testInstallerCreationShouldNotCreateVendorDirectory
     * @depends testInstallerCreationShouldNotCreateBinDirectory
     */
    public function testInstall()
    {
        $library = new LibraryInstaller($this->vendorDir, $this->binDir, $this->dm, $this->repository, $this->io);
        $package = $this->createPackageMock();

        $package
            ->expects($this->once())
            ->method('getPrettyName')
            ->will($this->returnValue('some/package'));

        $this->dm
            ->expects($this->once())
            ->method('download')
            ->with($package, $this->vendorDir.'/some/package');

        $this->repository
            ->expects($this->once())
            ->method('addPackage')
            ->with($package);

        $library->install($package);
        $this->assertFileExists($this->vendorDir, 'Vendor dir should be created');
        $this->assertFileExists($this->binDir, 'Bin dir should be created');
    }

    /**
     * @depends testInstallerCreationShouldNotCreateVendorDirectory
     * @depends testInstallerCreationShouldNotCreateBinDirectory
     */
    public function testUpdate()
    {
        $library = new LibraryInstaller($this->vendorDir, $this->binDir, $this->dm, $this->repository, $this->io);
        $initial = $this->createPackageMock();
        $target  = $this->createPackageMock();

        $initial
            ->expects($this->once())
            ->method('getPrettyName')
            ->will($this->returnValue('package1'));

        $this->repository
            ->expects($this->exactly(3))
            ->method('hasPackage')
            ->will($this->onConsecutiveCalls(true, false, false));

        $this->dm
            ->expects($this->once())
            ->method('update')
            ->with($initial, $target, $this->vendorDir.'/package1');

        $this->repository
            ->expects($this->once())
            ->method('removePackage')
            ->with($initial);

        $this->repository
            ->expects($this->once())
            ->method('addPackage')
            ->with($target);

        $library->update($initial, $target);
        $this->assertFileExists($this->vendorDir, 'Vendor dir should be created');
        $this->assertFileExists($this->binDir, 'Bin dir should be created');

        $this->setExpectedException('InvalidArgumentException');

        $library->update($initial, $target);
    }

    public function testUninstall()
    {
        $library = new LibraryInstaller($this->vendorDir, $this->binDir, $this->dm, $this->repository, $this->io);
        $package = $this->createPackageMock();

        $package
            ->expects($this->once())
            ->method('getPrettyName')
            ->will($this->returnValue('pkg'));

        $this->repository
            ->expects($this->exactly(2))
            ->method('hasPackage')
            ->with($package)
            ->will($this->onConsecutiveCalls(true, false));

        $this->dm
            ->expects($this->once())
            ->method('remove')
            ->with($package, $this->vendorDir.'/pkg');

        $this->repository
            ->expects($this->once())
            ->method('removePackage')
            ->with($package);

        $library->uninstall($package);

        // TODO re-enable once #125 is fixed and we throw exceptions again
//        $this->setExpectedException('InvalidArgumentException');

        $library->uninstall($package);
    }

    public function testGetInstallPath()
    {
        $library = new LibraryInstaller($this->vendorDir, $this->binDir, $this->dm, $this->repository, $this->io);
        $package = $this->createPackageMock();

        $package
            ->expects($this->once())
            ->method('getTargetDir')
            ->will($this->returnValue(null));

        $this->assertEquals($this->vendorDir.'/'.$package->getName(), $library->getInstallPath($package));
    }

    public function testGetInstallPathWithTargetDir()
    {
        $library = new LibraryInstaller($this->vendorDir, $this->binDir, $this->dm, $this->repository, $this->io);
        $package = $this->createPackageMock();

        $package
            ->expects($this->once())
            ->method('getTargetDir')
            ->will($this->returnValue('Some/Namespace'));
        $package
            ->expects($this->any())
            ->method('getPrettyName')
            ->will($this->returnValue('foo/bar'));

        $this->assertEquals($this->vendorDir.'/'.$package->getPrettyName().'/Some/Namespace', $library->getInstallPath($package));
    }

    private function createPackageMock()
    {
        return $this->getMockBuilder('Composer\Package\MemoryPackage')
            ->setConstructorArgs(array(md5(rand()), '1.0.0.0', '1.0.0'))
            ->getMock();
    }
}
