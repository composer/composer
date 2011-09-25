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
    public function testSetGetInstaller()
    {
        $installer = $this->createInstallerMock();
        $manager   = new InstallationManager();

        $manager->setInstaller('vendor', $installer);
        $this->assertSame($installer, $manager->getInstaller('vendor'));

        $this->setExpectedException('InvalidArgumentException');
        $manager->getInstaller('unregistered');
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
            ->with($installOperation);
        $manager
            ->expects($this->once())
            ->method('uninstall')
            ->with($removeOperation);
        $manager
            ->expects($this->once())
            ->method('update')
            ->with($updateOperation);

        $manager->execute($installOperation);
        $manager->execute($removeOperation);
        $manager->execute($updateOperation);
    }

    public function testInstall()
    {
        $installer = $this->createInstallerMock();
        $manager   = new InstallationManager();
        $manager->setInstaller('library', $installer);

        $package   = $this->createPackageMock();
        $operation = new InstallOperation($package, 'test');

        $package
            ->expects($this->once())
            ->method('getType')
            ->will($this->returnValue('library'));

        $installer
            ->expects($this->once())
            ->method('install')
            ->with($package);

        $manager->install($operation);
    }

    public function testUpdateWithEqualTypes()
    {
        $installer = $this->createInstallerMock();
        $manager   = new InstallationManager();
        $manager->setInstaller('library', $installer);

        $initial   = $this->createPackageMock();
        $target    = $this->createPackageMock();
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
            ->method('update')
            ->with($initial, $target);

        $manager->update($operation);
    }

    public function testUpdateWithNotEqualTypes()
    {
        $installer1 = $this->createInstallerMock();
        $installer2 = $this->createInstallerMock();
        $manager    = new InstallationManager();
        $manager->setInstaller('library', $installer1);
        $manager->setInstaller('bundles', $installer2);

        $initial   = $this->createPackageMock();
        $target    = $this->createPackageMock();
        $operation = new UpdateOperation($initial, $target, 'test');

        $initial
            ->expects($this->once())
            ->method('getType')
            ->will($this->returnValue('library'));
        $target
            ->expects($this->once())
            ->method('getType')
            ->will($this->returnValue('bundles'));

        $installer1
            ->expects($this->once())
            ->method('uninstall')
            ->with($initial);

        $installer2
            ->expects($this->once())
            ->method('install')
            ->with($target);

        $manager->update($operation);
    }

    public function testUninstall()
    {
        $installer = $this->createInstallerMock();
        $manager   = new InstallationManager();
        $manager->setInstaller('library', $installer);

        $package   = $this->createPackageMock();
        $operation = new UninstallOperation($package, 'test');

        $package
            ->expects($this->once())
            ->method('getType')
            ->will($this->returnValue('library'));

        $installer
            ->expects($this->once())
            ->method('uninstall')
            ->with($package);

        $manager->uninstall($operation);
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
