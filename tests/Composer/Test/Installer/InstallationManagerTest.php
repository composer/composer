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
use Composer\DependencyResolver\Operation\UnpackOperation;

class InstallationManagerTest extends \PHPUnit_Framework_TestCase
{
    public function testAddGetInstaller()
    {
        $installer = $this->createInstallerMock();

        $installer
            ->expects($this->exactly(2))
            ->method('supports')
            ->will($this->returnCallback(function ($arg) {
                return $arg === 'vendor';
            }));

        $manager   = new InstallationManager('vendor');

        $manager->addInstaller($installer);
        $this->assertSame($installer, $manager->getInstaller('vendor'));

        $this->setExpectedException('InvalidArgumentException');
        $manager->getInstaller('unregistered');
    }

    public function testExecute()
    {
        $manager = $this->getMockBuilder('Composer\Installer\InstallationManager')
            ->setMethods(array('install', 'update', 'uninstall'))
            ->setConstructorArgs(array('vendor'))
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
        $manager   = new InstallationManager('vendor');
        $manager->addInstaller($installer);

        $package   = $this->createPackageMock();
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
            ->with($package);

        $manager->install($operation);
    }

    public function testUpdateWithEqualTypes()
    {
        $installer = $this->createInstallerMock();
        $manager   = new InstallationManager('vendor');
        $manager->addInstaller($installer);

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
            ->method('supports')
            ->with('library')
            ->will($this->returnValue(true));

        $installer
            ->expects($this->once())
            ->method('update')
            ->with($initial, $target);

        $manager->update($operation);
    }

    public function testUpdateWithNotEqualTypes()
    {
        $libInstaller = $this->createInstallerMock();
        $bundleInstaller = $this->createInstallerMock();
        $manager    = new InstallationManager('vendor');
        $manager->addInstaller($libInstaller);
        $manager->addInstaller($bundleInstaller);

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
            ->with($initial);

        $bundleInstaller
            ->expects($this->once())
            ->method('install')
            ->with($target);

        $manager->update($operation);
    }

    public function testUninstall()
    {
        $installer = $this->createInstallerMock();
        $manager   = new InstallationManager('vendor');
        $manager->addInstaller($installer);

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

        $installer
            ->expects($this->once())
            ->method('supports')
            ->with('library')
            ->will($this->returnValue(true));

        $manager->uninstall($operation);
    }

    /**
     * @depends testInstall
     */
    public function testUnpack()
    {
        $installer = $this->createInstallerMock();
        $manager   = new InstallationManager('vendor');
        $manager->addInstaller($installer);

        $package   = $this->createPackageMock();
        $operation = new InstallOperation($package, 'test');
        $unpackOperation = new UnpackOperation($package, 'test');

        $package
            ->expects($this->exactly(2))
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
            ->with($package);

        $installer
            ->expects($this->once())
            ->method('unpack')
            ->with($package);

        $manager->install($operation);
        $manager->unpack($unpackOperation);
    }

    public function testGetInstallPath()
    {
        $expected = '/path/to/install';
        $installer = $this->createInstallerMock();
        $manager   = new InstallationManager('vendor');
        $manager->addInstaller($installer);

        $package   = $this->createPackageMock();

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
            ->method('getInstallPath')
            ->with($package)
            ->will($this->returnValue($expected));


        $manager->getInstallPath($package);
    }

    public function testIsInstalled()
    {
        $installer = $this->createInstallerMock();
        $manager   = new InstallationManager('vendor');
        $manager->addInstaller($installer);

        $packageA   = $this->createPackageMock();
        $packageB   = $this->createPackageMock();

        $packageA
            ->expects($this->once())
            ->method('getType')
            ->will($this->returnValue('library'));

        $packageB
            ->expects($this->once())
            ->method('getType')
            ->will($this->returnValue('bundle'));

        $installer
            ->expects($this->exactly(2))
            ->method('isInstalled')
            ->will($this->returnCallback(
                       function($package) {
                           return 'library' === $package->getType();
                       }
                   ));

        $this->assertTrue($manager->isPackageInstalled($packageA));
        $this->assertFalse($manager->isPackageInstalled($packageB));
    }

    public function testGetVendorPathAbsolute()
    {
        $manager = new InstallationManager('vendor');
        $this->assertEquals(getcwd().DIRECTORY_SEPARATOR.'vendor', $manager->getVendorPath(true));
    }

    public function testGetVendorPathRelative()
    {
        $manager = new InstallationManager('vendor');
        $this->assertEquals('vendor', $manager->getVendorPath());
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testExceptionIfVendorNotInBasePath()
    {
        new InstallationManager('/vendor' . microtime(true));
    }

    public function testVendorInBasePath()
    {
        $expect = microtime(true).DIRECTORY_SEPARATOR.'/vendor';
        $manager = new InstallationManager(getcwd().DIRECTORY_SEPARATOR.$expect);
        $this->assertEquals($expect, $manager->getVendorPath());
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
