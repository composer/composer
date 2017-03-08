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

use Composer\Installer\InstallationManager;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;

class InstallationManagerTest extends \PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        $this->repository = $this->getMock('Composer\Repository\InstalledRepositoryInterface');
    }

    public function testAddGetInstaller()
    {
        $installer = $this->createInstallerMock();

        $installer
            ->expects($this->exactly(2))
            ->method('supports')
            ->will($this->returnCallback(function ($arg) {
                return $arg === 'vendor';
            }));

        $manager = new InstallationManager();

        $manager->addInstaller($installer);
        $this->assertSame($installer, $manager->getInstaller('vendor'));

        $this->setExpectedException('InvalidArgumentException');
        $manager->getInstaller('unregistered');
    }

    public function testAddRemoveInstaller()
    {
        $installer = $this->createInstallerMock();

        $installer
            ->expects($this->exactly(2))
            ->method('supports')
            ->will($this->returnCallback(function ($arg) {
                return $arg === 'vendor';
            }));

        $installer2 = $this->createInstallerMock();

        $installer2
            ->expects($this->exactly(1))
            ->method('supports')
            ->will($this->returnCallback(function ($arg) {
                return $arg === 'vendor';
            }));

        $manager = new InstallationManager();

        $manager->addInstaller($installer);
        $this->assertSame($installer, $manager->getInstaller('vendor'));
        $manager->addInstaller($installer2);
        $this->assertSame($installer2, $manager->getInstaller('vendor'));
        $manager->removeInstaller($installer2);
        $this->assertSame($installer, $manager->getInstaller('vendor'));
    }

    public function testExecute()
    {
        $manager = $this->getMockBuilder('Composer\Installer\InstallationManager')
            ->setMethods(array('install', 'update', 'uninstall'))
            ->getMock();

        $installOperation = new InstallOperation($this->createPackageMock());
        $removeOperation = new UninstallOperation($this->createPackageMock());
        $updateOperation = new UpdateOperation(
            $this->createPackageMock(), $this->createPackageMock()
        );

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

        $manager->execute($this->repository, $installOperation);
        $manager->execute($this->repository, $removeOperation);
        $manager->execute($this->repository, $updateOperation);
    }

    public function testInstall()
    {
        $installer = $this->createInstallerMock();
        $manager = new InstallationManager();
        $manager->addInstaller($installer);

        $package = $this->createPackageMock();
        $operation = new InstallOperation($package, 'test');

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

    public function testUpdateWithEqualTypes()
    {
        $installer = $this->createInstallerMock();
        $manager = new InstallationManager();
        $manager->addInstaller($installer);

        $initial = $this->createPackageMock();
        $target = $this->createPackageMock();
        $operation = new UpdateOperation($initial, $target, 'test');

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

    public function testUpdateWithNotEqualTypes()
    {
        $libInstaller = $this->createInstallerMock();
        $bundleInstaller = $this->createInstallerMock();
        $manager = new InstallationManager();
        $manager->addInstaller($libInstaller);
        $manager->addInstaller($bundleInstaller);

        $initial = $this->createPackageMock();
        $target = $this->createPackageMock();
        $operation = new UpdateOperation($initial, $target, 'test');

        $initial
            ->expects($this->once())
            ->method('getType')
            ->will($this->returnValue('library'));
        $target
            ->expects($this->once())
            ->method('getType')
            ->will($this->returnValue('bundles'));

        $bundleInstaller
            ->expects($this->exactly(2))
            ->method('supports')
            ->will($this->returnCallback(function ($arg) {
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

        $manager->update($this->repository, $operation);
    }

    public function testUninstall()
    {
        $installer = $this->createInstallerMock();
        $manager = new InstallationManager();
        $manager->addInstaller($installer);

        $package = $this->createPackageMock();
        $operation = new UninstallOperation($package, 'test');

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

    public function testInstallBinary()
    {
        $installer = $this->getMockBuilder('Composer\Installer\LibraryInstaller')
            ->disableOriginalConstructor()
            ->getMock();
        $manager = new InstallationManager();
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

    private function createInstallerMock()
    {
        return $this->getMockBuilder('Composer\Installer\InstallerInterface')
            ->getMock();
    }

    private function createPackageMock()
    {
        return $this->getMockBuilder('Composer\Package\PackageInterface')
            ->getMock();
    }
}
