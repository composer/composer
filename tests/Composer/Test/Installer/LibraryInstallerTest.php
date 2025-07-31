<?php declare(strict_types=1);

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
use Composer\Repository\InstalledArrayRepository;
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

        $this->rootDir = self::getUniqueTmpDirectory();
        $this->vendorDir = $this->rootDir.DIRECTORY_SEPARATOR.'vendor';
        self::ensureDirectoryExistsAndClear($this->vendorDir);

        $this->binDir = $this->rootDir.DIRECTORY_SEPARATOR.'bin';
        self::ensureDirectoryExistsAndClear($this->binDir);

        $this->config->merge([
            'config' => [
                'vendor-dir' => $this->vendorDir,
                'bin-dir' => $this->binDir,
            ],
        ]);

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
        self::assertFileDoesNotExist($this->vendorDir);
    }

    public function testInstallerCreationShouldNotCreateBinDirectory(): void
    {
        $this->fs->removeDirectory($this->binDir);

        new LibraryInstaller($this->io, $this->composer);
        self::assertFileDoesNotExist($this->binDir);
    }

    public function testIsInstalled(): void
    {
        $library = new LibraryInstaller($this->io, $this->composer);
        $package = self::getPackage('test/pkg', '1.0.0');

        $repository = new InstalledArrayRepository();
        self::assertFalse($library->isInstalled($repository, $package));

        // package being in repo is not enough to be installed
        $repository->addPackage($package);
        self::assertFalse($library->isInstalled($repository, $package));

        // package being in repo and vendor/pkg/foo dir present means it is seen as installed
        self::ensureDirectoryExistsAndClear($this->vendorDir.'/'.$package->getPrettyName());
        self::assertTrue($library->isInstalled($repository, $package));

        // package in symlinked directory is also seen as installed
        $this->fs->removeDirectory($this->vendorDir.'/'.$package->getPrettyName());
        self::ensureDirectoryExistsAndClear($this->vendorDir.'/test/pkg-link-target');
        symlink($this->vendorDir.'/test/pkg-link-target', $this->vendorDir.'/'.$package->getPrettyName());
        self::assertTrue($library->isInstalled($repository, $package));

        // package in broken symlinked directory is not installed
        $this->fs->rmdir($this->vendorDir.'/test/pkg-link-target');
        self::assertFalse($library->isInstalled($repository, $package));

        $repository->removePackage($package);
        self::assertFalse($library->isInstalled($repository, $package));
    }

    /**
     * @depends testInstallerCreationShouldNotCreateVendorDirectory
     * @depends testInstallerCreationShouldNotCreateBinDirectory
     */
    public function testInstall(): void
    {
        $library = new LibraryInstaller($this->io, $this->composer);
        $package = self::getPackage('some/package', '1.0.0');

        $this->dm
            ->expects($this->once())
            ->method('install')
            ->with($package, $this->vendorDir.'/some/package')
            ->will($this->returnValue(\React\Promise\resolve(null)));

        $this->repository
            ->expects($this->once())
            ->method('addPackage')
            ->with($package);

        $library->install($this->repository, $package);
        self::assertFileExists($this->vendorDir, 'Vendor dir should be created');
        self::assertFileExists($this->binDir, 'Bin dir should be created');
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
          ->with($this->vendorDir.'/vendor/package1/oldtarget', $this->vendorDir.'/vendor/package1/newtarget');

        $initial = self::getPackage('vendor/package1', '1.0.0');
        $target = self::getPackage('vendor/package1', '2.0.0');

        $initial->setTargetDir('oldtarget');
        $target->setTargetDir('newtarget');

        $this->repository
            ->expects($this->exactly(3))
            ->method('hasPackage')
            ->will($this->onConsecutiveCalls(true, false, false));

        $this->dm
            ->expects($this->once())
            ->method('update')
            ->with($initial, $target, $this->vendorDir.'/vendor/package1/newtarget')
            ->will($this->returnValue(\React\Promise\resolve(null)));

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
        self::assertFileExists($this->vendorDir, 'Vendor dir should be created');
        self::assertFileExists($this->binDir, 'Bin dir should be created');

        self::expectException('InvalidArgumentException');

        $library->update($this->repository, $initial, $target);
    }

    public function testUninstall(): void
    {
        $library = new LibraryInstaller($this->io, $this->composer);
        $package = self::getPackage('vendor/pkg', '1.0.0');

        $this->repository
            ->expects($this->exactly(2))
            ->method('hasPackage')
            ->with($package)
            ->will($this->onConsecutiveCalls(true, false));

        $this->dm
            ->expects($this->once())
            ->method('remove')
            ->with($package, $this->vendorDir.'/vendor/pkg')
            ->will($this->returnValue(\React\Promise\resolve(null)));

        $this->repository
            ->expects($this->once())
            ->method('removePackage')
            ->with($package);

        $library->uninstall($this->repository, $package);

        self::expectException('InvalidArgumentException');

        $library->uninstall($this->repository, $package);
    }

    public function testGetInstallPathWithoutTargetDir(): void
    {
        $library = new LibraryInstaller($this->io, $this->composer);
        $package = self::getPackage('Vendor/Pkg', '1.0.0');

        self::assertEquals($this->vendorDir.'/'.$package->getPrettyName(), $library->getInstallPath($package));
    }

    public function testGetInstallPathWithTargetDir(): void
    {
        $library = new LibraryInstaller($this->io, $this->composer);
        $package = self::getPackage('Foo/Bar', '1.0.0');
        $package->setTargetDir('Some/Namespace');

        self::assertEquals($this->vendorDir.'/'.$package->getPrettyName().'/Some/Namespace', $library->getInstallPath($package));
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
        $package = self::getPackage('foo/bar', '1.0.0');

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
}
