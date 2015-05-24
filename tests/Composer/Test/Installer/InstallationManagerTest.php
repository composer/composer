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
                return $arg->getType() === 'vendor';
            }));

        $manager   = new InstallationManager();

        $package = $this->createPackageMock();
        $package
            ->expects($this->exactly(2))
            ->method('getUniqueName')
            ->will($this->returnValue('foo/bar-1.0.0'));
        $package
            ->expects($this->once())
            ->method('getType')
            ->will($this->returnValue('vendor'));

        $manager->addInstaller($installer);
        $this->assertSame($installer, $manager->getInstaller($package));

        $invalid = $this->createPackageMock();
        $invalid
            ->expects($this->once())
            ->method('getUniqueName')
            ->will($this->returnValue('foo/bar-2.0.0'));
        $invalid
            ->expects($this->exactly(2))
            ->method('getType')
            ->will($this->returnValue('unregistered'));

        $this->setExpectedException('InvalidArgumentException');
        $manager->getInstaller($invalid);
    }

    public function testAddRemoveInstaller()
    {
        $installer = $this->createInstallerMock();

        $installer
            ->expects($this->exactly(2))
            ->method('supports')
            ->will($this->returnCallback(function ($arg) {
                return $arg->getType() === 'vendor';
            }));

        $installer2 = $this->createInstallerMock();

        $installer2
            ->expects($this->exactly(1))
            ->method('supports')
            ->will($this->returnCallback(function ($arg) {
                return $arg->getType() === 'vendor';
            }));

        $manager   = new InstallationManager();

        $package = $this->createPackageMock();
        $package
            ->expects($this->exactly(6))
            ->method('getUniqueName')
            ->will($this->returnValue('foo/bar-1.0.0'));
        $package
            ->expects($this->exactly(3))
            ->method('getType')
            ->will($this->returnValue('vendor'));

        $manager->addInstaller($installer);
        $this->assertSame($installer, $manager->getInstaller($package));
        $manager->addInstaller($installer2);
        $this->assertSame($installer2, $manager->getInstaller($package));
        $manager->removeInstaller($installer2);
        $this->assertSame($installer, $manager->getInstaller($package));
    }

    public function testExecute()
    {
        $manager = $this->getMockBuilder('Composer\Installer\InstallationManager')
            ->setMethods(array('install', 'update', 'uninstall'))
            ->getMock();

        $installOperation = new InstallOperation($this->createPackageMock());
        $removeOperation  = new UninstallOperation($this->createPackageMock());
        $updateOperation  = new UpdateOperation(
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
        $manager   = new InstallationManager();
        $manager->addInstaller($installer);

        $package   = $this->createPackageMock();
        $operation = new InstallOperation($package, 'test');

        $package
            ->expects($this->never())
            ->method('getType');

        $installer
            ->expects($this->once())
            ->method('supports')
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
        $manager   = new InstallationManager();
        $manager->addInstaller($installer);

        $initial   = $this->createPackageMock();
        $target    = $this->createPackageMock();
        $operation = new UpdateOperation($initial, $target, 'test');

        $initial
            ->expects($this->exactly(2))
            ->method('getUniqueName')
            ->will($this->returnValue('foo/bar-1.0.0'));
        $initial
            ->expects($this->once())
            ->method('getType')
            ->will($this->returnValue('library'));
        $target
            ->expects($this->never())
            ->method('getUniqueName');
        $target
            ->expects($this->once())
            ->method('getType')
            ->will($this->returnValue('library'));

        $installer
            ->expects($this->once())
            ->method('supports')
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
        $manager    = new InstallationManager();
        $manager->addInstaller($libInstaller);
        $manager->addInstaller($bundleInstaller);

        $initial   = $this->createPackageMock();
        $target    = $this->createPackageMock();
        $operation = new UpdateOperation($initial, $target, 'test');

        $initial
            ->expects($this->exactly(2))
            ->method('getUniqueName')
            ->will($this->returnValue('foo/bar-1.0.0'));
        $initial
            ->expects($this->exactly(2))
            ->method('getType')
            ->will($this->returnValue('library'));
        $target
            ->expects($this->exactly(2))
            ->method('getUniqueName')
            ->will($this->returnValue('foo/bar-2.0.0'));
        $target
            ->expects($this->exactly(2))
            ->method('getType')
            ->will($this->returnValue('bundles'));

        $bundleInstaller
            ->expects($this->exactly(2))
            ->method('supports')
            ->will($this->returnCallback(function ($arg) {
                return $arg->getType() === 'bundles';
            }));

        $libInstaller
            ->expects($this->once())
            ->method('supports')
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
        $manager   = new InstallationManager();
        $manager->addInstaller($installer);

        $package   = $this->createPackageMock();
        $operation = new UninstallOperation($package, 'test');

        $package
            ->expects($this->never())
            ->method('getType')
            ->will($this->returnValue('library'));

        $installer
            ->expects($this->once())
            ->method('uninstall')
            ->with($this->repository, $package);

        $installer
            ->expects($this->once())
            ->method('supports')
            ->will($this->returnValue(true));

        $manager->uninstall($this->repository, $operation);
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
