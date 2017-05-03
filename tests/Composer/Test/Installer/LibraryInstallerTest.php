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
use Composer\Util\Filesystem;
use Composer\TestCase;
use Composer\Composer;
use Composer\Config;

class LibraryInstallerTest extends TestCase
{
    protected $composer;
    protected $config;
    protected $rootDir;
    protected $vendorDir;
    protected $binDir;
    protected $dm;
    protected $repository;
    protected $io;
    protected $fs;

    protected function setUp()
    {
        $this->fs = new Filesystem;

        $this->composer = new Composer();
        $this->config = new Config();
        $this->composer->setConfig($this->config);

        $this->rootDir = $this->getUniqueTmpDirectory();
        $this->vendorDir = $this->rootDir.DIRECTORY_SEPARATOR.'vendor';
        $this->ensureDirectoryExistsAndClear($this->vendorDir);

        $this->binDir = $this->rootDir.DIRECTORY_SEPARATOR.'bin';
        $this->ensureDirectoryExistsAndClear($this->binDir);

        $this->config->merge(array(
            'config' => array(
                'vendor-dir' => $this->vendorDir,
                'bin-dir' => $this->binDir,
            ),
        ));

        $this->dm = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->disableOriginalConstructor()
            ->getMock();
        $this->composer->setDownloadManager($this->dm);

        $this->repository = $this->getMock('Composer\Repository\InstalledRepositoryInterface');
        $this->io = $this->getMock('Composer\IO\IOInterface');
    }

    protected function tearDown()
    {
        $this->fs->removeDirectory($this->rootDir);
    }

    public function testInstallerCreationShouldNotCreateVendorDirectory()
    {
        $this->fs->removeDirectory($this->vendorDir);

        new LibraryInstaller($this->io, $this->composer);
        $this->assertFileNotExists($this->vendorDir);
    }

    public function testInstallerCreationShouldNotCreateBinDirectory()
    {
        $this->fs->removeDirectory($this->binDir);

        new LibraryInstaller($this->io, $this->composer);
        $this->assertFileNotExists($this->binDir);
    }

    public function testIsInstalled()
    {
        $library = new LibraryInstaller($this->io, $this->composer);
        $package = $this->createPackageMock();

        $this->repository
            ->expects($this->exactly(2))
            ->method('hasPackage')
            ->with($package)
            ->will($this->onConsecutiveCalls(true, false));

        $this->assertTrue($library->isInstalled($this->repository, $package));
        $this->assertFalse($library->isInstalled($this->repository, $package));
    }

    /**
     * @depends testInstallerCreationShouldNotCreateVendorDirectory
     * @depends testInstallerCreationShouldNotCreateBinDirectory
     */
    public function testInstall()
    {
        $library = new LibraryInstaller($this->io, $this->composer);
        $package = $this->createPackageMock();

        $package
            ->expects($this->any())
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

        $library->install($this->repository, $package);
        $this->assertFileExists($this->vendorDir, 'Vendor dir should be created');
        $this->assertFileExists($this->binDir, 'Bin dir should be created');
    }

    /**
     * @depends testInstallerCreationShouldNotCreateVendorDirectory
     * @depends testInstallerCreationShouldNotCreateBinDirectory
     */
    public function testUpdate()
    {
        $filesystem = $this->getMockBuilder('Composer\Util\Filesystem')
          ->getMock();
        $filesystem
          ->expects($this->once())
          ->method('rename')
          ->with($this->vendorDir.'/package1/oldtarget', $this->vendorDir.'/package1/newtarget');

        $initial = $this->createPackageMock();
        $target = $this->createPackageMock();

        $initial
            ->expects($this->any())
            ->method('getPrettyName')
            ->will($this->returnValue('package1'));

        $initial
            ->expects($this->any())
            ->method('getTargetDir')
            ->will($this->returnValue('oldtarget'));

        $target
            ->expects($this->any())
            ->method('getPrettyName')
            ->will($this->returnValue('package1'));

        $target
            ->expects($this->any())
            ->method('getTargetDir')
            ->will($this->returnValue('newtarget'));

        $this->repository
            ->expects($this->exactly(3))
            ->method('hasPackage')
            ->will($this->onConsecutiveCalls(true, false, false));

        $this->dm
            ->expects($this->once())
            ->method('update')
            ->with($initial, $target, $this->vendorDir.'/package1/newtarget');

        $this->repository
            ->expects($this->once())
            ->method('removePackage')
            ->with($initial);

        $this->repository
            ->expects($this->once())
            ->method('addPackage')
            ->with($target);

        $library = new LibraryInstaller($this->io, $this->composer, 'library', $filesystem);
        $library->update($this->repository, $initial, $target);
        $this->assertFileExists($this->vendorDir, 'Vendor dir should be created');
        $this->assertFileExists($this->binDir, 'Bin dir should be created');

        $this->setExpectedException('InvalidArgumentException');

        $library->update($this->repository, $initial, $target);
    }

    public function testUninstall()
    {
        $library = new LibraryInstaller($this->io, $this->composer);
        $package = $this->createPackageMock();

        $package
            ->expects($this->any())
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

        $library->uninstall($this->repository, $package);

        $this->setExpectedException('InvalidArgumentException');

        $library->uninstall($this->repository, $package);
    }

    public function testGetInstallPath()
    {
        $library = new LibraryInstaller($this->io, $this->composer);
        $package = $this->createPackageMock();

        $package
            ->expects($this->once())
            ->method('getTargetDir')
            ->will($this->returnValue(null));

        $this->assertEquals($this->vendorDir.'/'.$package->getName(), $library->getInstallPath($package));
    }

    public function testGetInstallPathWithTargetDir()
    {
        $library = new LibraryInstaller($this->io, $this->composer);
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

    /**
     * @depends testInstallerCreationShouldNotCreateVendorDirectory
     * @depends testInstallerCreationShouldNotCreateBinDirectory
     */
    public function testEnsureBinariesInstalled()
    {
        $binaryInstallerMock = $this->getMockBuilder('Composer\Installer\BinaryInstaller')
            ->disableOriginalConstructor()
            ->getMock();

        $library = new LibraryInstaller($this->io, $this->composer, 'library', null, $binaryInstallerMock);
        $package = $this->createPackageMock();

        $binaryInstallerMock
            ->expects($this->never())
            ->method('removeBinaries')
            ->with($package);

        $binaryInstallerMock
            ->expects($this->once())
            ->method('installBinaries')
            ->with($package, $library->getInstallPath($package), false);

        $library->ensureBinariesPresence($package);
    }

    protected function createPackageMock()
    {
        return $this->getMockBuilder('Composer\Package\Package')
            ->setConstructorArgs(array(md5(mt_rand()), '1.0.0.0', '1.0.0'))
            ->getMock();
    }
}
