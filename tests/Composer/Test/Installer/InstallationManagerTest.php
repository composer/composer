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

use Composer\Installer\InstallationManager;
use Composer\Installer\NoopInstaller;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\Package\Link;
use Composer\Package\Package;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Test\TestCase;

class InstallationManagerTest extends TestCase
{
    /**
     * @var \Composer\Repository\InstalledRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    protected $repository;

    /**
     * @var \Composer\Util\Loop&\PHPUnit\Framework\MockObject\MockObject
     */
    protected $loop;

    /**
     * @var \Composer\IO\IOInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    protected $io;

    public function setUp(): void
    {
        $this->loop = $this->getMockBuilder('Composer\Util\Loop')->disableOriginalConstructor()->getMock();
        $this->repository = $this->getMockBuilder('Composer\Repository\InstalledRepositoryInterface')->getMock();
        $this->io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();
    }

    public function testAddGetInstaller(): void
    {
        $installer = $this->createInstallerMock();

        $installer
            ->expects($this->exactly(2))
            ->method('supports')
            ->will($this->returnCallback(static function ($arg): bool {
                return $arg === 'vendor';
            }));

        $manager = new InstallationManager($this->loop, $this->io);

        $manager->addInstaller($installer);
        self::assertSame($installer, $manager->getInstaller('vendor'));

        self::expectException('InvalidArgumentException');
        $manager->getInstaller('unregistered');
    }

    public function testAddRemoveInstaller(): void
    {
        $installer = $this->createInstallerMock();

        $installer
            ->expects($this->exactly(2))
            ->method('supports')
            ->will($this->returnCallback(static function ($arg): bool {
                return $arg === 'vendor';
            }));

        $installer2 = $this->createInstallerMock();

        $installer2
            ->expects($this->exactly(1))
            ->method('supports')
            ->will($this->returnCallback(static function ($arg): bool {
                return $arg === 'vendor';
            }));

        $manager = new InstallationManager($this->loop, $this->io);

        $manager->addInstaller($installer);
        self::assertSame($installer, $manager->getInstaller('vendor'));
        $manager->addInstaller($installer2);
        self::assertSame($installer2, $manager->getInstaller('vendor'));
        $manager->removeInstaller($installer2);
        self::assertSame($installer, $manager->getInstaller('vendor'));
    }

    public function testExecute(): void
    {
        $manager = $this->getMockBuilder('Composer\Installer\InstallationManager')
            ->setConstructorArgs([$this->loop, $this->io])
            ->onlyMethods(['install', 'update', 'uninstall'])
            ->getMock();

        $installOperation = new InstallOperation($package = $this->createPackageMock());
        $removeOperation = new UninstallOperation($package);
        $updateOperation = new UpdateOperation(
            $package,
            $package
        );

        $package->expects($this->any())
            ->method('getType')
            ->will($this->returnValue('library'));

        $manager
            ->expects($this->once())
            ->method('install')
            ->with($this->repository, $installOperation);
        $manager
            ->expects($this->once())
            ->method('uninstall')
            ->with($this->repository, $removeOperation);
        $manager
            ->expects($this->once())
            ->method('update')
            ->with($this->repository, $updateOperation);

        $manager->addInstaller(new NoopInstaller());
        $manager->execute($this->repository, [$installOperation, $removeOperation, $updateOperation]);
    }

    public function testInstall(): void
    {
        $installer = $this->createInstallerMock();
        $manager = new InstallationManager($this->loop, $this->io);
        $manager->addInstaller($installer);

        $package = $this->createPackageMock();
        $operation = new InstallOperation($package);

        $package
            ->expects($this->once())
            ->method('getType')
            ->will($this->returnValue('library'));

        $installer
            ->expects($this->once())
            ->method('supports')
            ->with('library')
            ->will($this->returnValue(true));

        $installer
            ->expects($this->once())
            ->method('install')
            ->with($this->repository, $package);

        $manager->install($this->repository, $operation);
    }

    public function testUpdateWithEqualTypes(): void
    {
        $installer = $this->createInstallerMock();
        $manager = new InstallationManager($this->loop, $this->io);
        $manager->addInstaller($installer);

        $initial = $this->createPackageMock();
        $target = $this->createPackageMock();
        $operation = new UpdateOperation($initial, $target);

        $initial
            ->expects($this->once())
            ->method('getType')
            ->will($this->returnValue('library'));
        $target
            ->expects($this->once())
            ->method('getType')
            ->will($this->returnValue('library'));

        $installer
            ->expects($this->once())
            ->method('supports')
            ->with('library')
            ->will($this->returnValue(true));

        $installer
            ->expects($this->once())
            ->method('update')
            ->with($this->repository, $initial, $target);

        $manager->update($this->repository, $operation);
    }

    public function testUpdateWithNotEqualTypes(): void
    {
        $libInstaller = $this->createInstallerMock();
        $bundleInstaller = $this->createInstallerMock();
        $manager = new InstallationManager($this->loop, $this->io);
        $manager->addInstaller($libInstaller);
        $manager->addInstaller($bundleInstaller);

        $initial = $this->createPackageMock();
        $initial
            ->expects($this->once())
            ->method('getType')
            ->will($this->returnValue('library'));

        $target = $this->createPackageMock();
        $target
            ->expects($this->once())
            ->method('getType')
            ->will($this->returnValue('bundles'));

        $bundleInstaller
            ->expects($this->exactly(2))
            ->method('supports')
            ->will($this->returnCallback(static function ($arg): bool {
                return $arg === 'bundles';
            }));

        $libInstaller
            ->expects($this->once())
            ->method('supports')
            ->with('library')
            ->will($this->returnValue(true));

        $libInstaller
            ->expects($this->once())
            ->method('uninstall')
            ->with($this->repository, $initial);

        $bundleInstaller
            ->expects($this->once())
            ->method('install')
            ->with($this->repository, $target);

        $operation = new UpdateOperation($initial, $target);
        $manager->update($this->repository, $operation);
    }

    public function testUninstall(): void
    {
        $installer = $this->createInstallerMock();
        $manager = new InstallationManager($this->loop, $this->io);
        $manager->addInstaller($installer);

        $package = $this->createPackageMock();
        $operation = new UninstallOperation($package);

        $package
            ->expects($this->once())
            ->method('getType')
            ->will($this->returnValue('library'));

        $installer
            ->expects($this->once())
            ->method('uninstall')
            ->with($this->repository, $package);

        $installer
            ->expects($this->once())
            ->method('supports')
            ->with('library')
            ->will($this->returnValue(true));

        $manager->uninstall($this->repository, $operation);
    }

    public function testInstallBinary(): void
    {
        $installer = $this->getMockBuilder('Composer\Installer\LibraryInstaller')
            ->disableOriginalConstructor()
            ->getMock();
        $manager = new InstallationManager($this->loop, $this->io);
        $manager->addInstaller($installer);

        $package = $this->createPackageMock();

        $package
            ->expects($this->once())
            ->method('getType')
            ->will($this->returnValue('library'));

        $installer
            ->expects($this->once())
            ->method('supports')
            ->with('library')
            ->will($this->returnValue(true));

        $installer
            ->expects($this->once())
            ->method('ensureBinariesPresence')
            ->with($package);

        $manager->ensureBinariesPresence($package);
    }

    public function testPackageClassPreloadDoesNotScanNestedVendorDirectory(): void
    {
        $installPath = self::getUniqueTmpDirectory();
        self::ensureDirectoryExistsAndClear($installPath.'/src/Foo');
        self::ensureDirectoryExistsAndClear($installPath.'/vendor/Foo');

        file_put_contents($installPath.'/src/Foo/Runtime.php', '<?php namespace Foo; class Runtime {}');
        file_put_contents($installPath.'/vendor/Foo/DevDependency.php', '<?php namespace Foo; class DevDependency {}');

        $manager = new InstallationManager($this->loop, $this->io);
        $method = new \ReflectionMethod($manager, 'buildPackageClassMap');
        $method->setAccessible(true);

        $classMap = $method->invoke($manager, ['psr-4' => ['Foo\\' => 'src/Foo']], $installPath);

        self::assertArrayHasKey('Foo\\Runtime', $classMap);
        self::assertArrayNotHasKey('Foo\\DevDependency', $classMap);
    }

    public function testRuntimeDependencyClassPreloadRunsWhenOnlyDependencyIsUpdated(): void
    {
        $runtimeClass = 'Composer\\Test\\Installer\\RuntimeDependencyPreloadFixture\\Finder';
        self::assertFalse(class_exists($runtimeClass, false));

        $composerInstallPath = realpath(dirname(__DIR__, 4));
        self::assertIsString($composerInstallPath);

        $finderInstallPath = self::getUniqueTmpDirectory();
        self::ensureDirectoryExistsAndClear($finderInstallPath.'/src');
        file_put_contents($finderInstallPath.'/src/Finder.php', '<?php namespace Composer\Test\Installer\RuntimeDependencyPreloadFixture; class Finder {}');

        $composerPackage = new Package('composer/composer', '2.10.1.0', '2.10.1');
        $composerPackage->setRequires([
            'symfony/finder' => new Link('composer/composer', 'symfony/finder', new MatchAllConstraint()),
        ]);

        $initialFinderPackage = new Package('symfony/finder', '8.0.8.0', '8.0.8');
        $initialFinderPackage->setAutoload([
            'classmap' => ['src/Finder.php'],
        ]);

        $targetFinderPackage = new Package('symfony/finder', '8.1.0.0', '8.1.0');
        $operation = new UpdateOperation($initialFinderPackage, $targetFinderPackage);

        $repository = $this->getMockBuilder('Composer\Repository\InstalledRepositoryInterface')->getMock();
        $repository
            ->expects($this->exactly(2))
            ->method('getPackages')
            ->willReturn([$composerPackage, $initialFinderPackage]);

        $manager = $this->getMockBuilder(InstallationManager::class)
            ->setConstructorArgs([$this->loop, $this->io])
            ->onlyMethods(['getInstallPath'])
            ->getMock();

        $manager
            ->expects($this->exactly(2))
            ->method('getInstallPath')
            ->willReturnMap([
                [$composerPackage, $composerInstallPath],
                [$initialFinderPackage, $finderInstallPath],
            ]);

        $method = new \ReflectionMethod($manager, 'preloadRuntimeClassesBeforeSelfUpdate');
        $method->setAccessible(true);
        $method->invoke($manager, $repository, [$operation]);

        self::assertTrue(class_exists($runtimeClass, false));
    }

    /**
     * @return \Composer\Installer\InstallerInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function createInstallerMock()
    {
        return $this->getMockBuilder('Composer\Installer\InstallerInterface')
            ->getMock();
    }

    /**
     * @return \Composer\Package\PackageInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function createPackageMock()
    {
        $mock = $this->getMockBuilder('Composer\Package\PackageInterface')
            ->getMock();

        return $mock;
    }
}
