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
use Composer\Test\TestCase;
use Composer\Composer;
use Composer\Config;

class LibraryInstallerTest extends TestCase
{
    /**
     * @var \Composer\Composer
     */
    protected $composer;

    /**
     * @var \Composer\Config
     */
    protected $config;

    /**
     * @var string
     */
    protected $rootDir;

    /**
     * @var string
     */
    protected $vendorDir;

    /**
     * @var string
     */
    protected $binDir;

    /**
     * @var \Composer\Downloader\DownloadManager&\PHPUnit\Framework\MockObject\MockObject
     */
    protected $dm;

    /**
     * @var \Composer\Repository\InstalledRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    protected $repository;

    /**
     * @var \Composer\IO\IOInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    protected $io;

    /**
     * @var \Composer\Util\Filesystem
     */
    protected $fs;

    protected function setUp(): void
    {
        $this->fs = new Filesystem;

        $this->composer = new Composer();
        $this->config = new Config(false);
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

        $this->repository = $this->getMockBuilder('Composer\Repository\InstalledRepositoryInterface')->getMock();
        $this->io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->fs->removeDirectory($this->rootDir);
    }

    public function testInstallerCreationShouldNotCreateVendorDirectory(): void
    {
        $this->fs->removeDirectory($this->vendorDir);

        new LibraryInstaller($this->io, $this->composer);
        $this->assertFileDoesNotExist($this->vendorDir);
    }

    public function testInstallerCreationShouldNotCreateBinDirectory(): void
    {
        $this->fs->removeDirectory($this->binDir);

        new LibraryInstaller($this->io, $this->composer);
        $this->assertFileDoesNotExist($this->binDir);
    }

    public function testIsInstalled(): void
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
    public function testInstall(): void
    {
        $library = new LibraryInstaller($this->io, $this->composer);
        $package = $this->createPackageMock();

        $package
            ->expects($this->any())
            ->method('getPrettyName')
            ->will($this->returnValue('some/package'));

        $this->dm
            ->expects($this->once())
            ->method('install')
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
    public function testUpdate(): void
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

        self::expectException('InvalidArgumentException');

        $library->update($this->repository, $initial, $target);
    }

    public function testUninstall(): void
    {
        $library = new LibraryInstaller($this->io, $this->composer);
        $package = $this->createPackageMock();

        $package
            ->expects($this->any())
            ->method('getPrettyName')
            ->will($this->returnValue('pkg'));
        $package
            ->expects($this->any())
            ->method('getName')
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

        self::expectException('InvalidArgumentException');

        $library->uninstall($this->repository, $package);
    }

    public function testGetInstallPath(): void
    {
        $library = new LibraryInstaller($this->io, $this->composer);
        $package = $this->createPackageMock();

        $package
            ->expects($this->once())
            ->method('getTargetDir')
            ->will($this->returnValue(null));

        $this->assertEquals($this->vendorDir.'/'.$package->getName(), $library->getInstallPath($package));
    }

    public function testGetInstallPathWithTargetDir(): void
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
    public function testEnsureBinariesInstalled(): void
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

    /**
     * @return \Composer\Package\PackageInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    protected function createPackageMock()
    {
        return $this->getMockBuilder('Composer\Package\Package')
            ->setConstructorArgs(array(md5((string) mt_rand()), '1.0.0.0', '1.0.0'))
            ->getMock();
    }
}
