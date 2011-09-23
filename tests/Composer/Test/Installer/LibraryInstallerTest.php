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

class LibraryInstallerTest extends \PHPUnit_Framework_TestCase
{
    private $dir;
    private $dm;
    private $registry;
    private $library;

    protected function setUp()
    {
        $this->dir = sys_get_temp_dir().'/composer';
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }

        $this->dm = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->disableOriginalConstructor()
            ->getMock();

        $this->registry = $this->getMockBuilder('Composer\Installer\Registry\RegistryInterface')
            ->disableOriginalConstructor()
            ->getMock();
    }

    public function testInstallerCreation()
    {
        $this->registry
            ->expects($this->once())
            ->method('open');

        $this->registry
            ->expects($this->once())
            ->method('close');

        $library = new LibraryInstaller($this->dir, $this->dm, $this->registry);
        $this->assertTrue(is_dir($this->dir));

        $file = sys_get_temp_dir().'/file';
        touch($file);

        $this->setExpectedException('UnexpectedValueException');
        $library = new LibraryInstaller($file, $this->dm, $this->registry);
    }

    public function testExecuteOperation()
    {
        $library = $this->getMockBuilder('Composer\Installer\LibraryInstaller')
            ->setConstructorArgs(array($this->dir, $this->dm, $this->registry))
            ->setMethods(array('install', 'update', 'uninstall'))
            ->getMock();

        $packageToInstall = $this->createPackageMock();
        $packageToRemove  = $this->createPackageMock();
        $packageToUpdate  = $this->createPackageMock();
        $updatedPackage   = $this->createPackageMock();

        $library
            ->expects($this->once())
            ->method('install')
            ->with($packageToInstall);

        $library
            ->expects($this->once())
            ->method('uninstall')
            ->with($packageToRemove);

        $library
            ->expects($this->once())
            ->method('update')
            ->with($packageToUpdate, $updatedPackage);

        $library->executeOperation(new Operation\InstallOperation($packageToInstall));
        $library->executeOperation(new Operation\UninstallOperation($packageToRemove));
        $library->executeOperation(new Operation\UpdateOperation($packageToUpdate, $updatedPackage));
    }

    public function testIsInstalled()
    {
        $library = new LibraryInstaller($this->dir, $this->dm, $this->registry);
        $package = $this->createPackageMock();

        $this->registry
            ->expects($this->exactly(2))
            ->method('isPackageRegistered')
            ->with($package)
            ->will($this->onConsecutiveCalls(true, false));

        $this->assertTrue($library->isInstalled($package));
        $this->assertFalse($library->isInstalled($package));
    }

    public function testInstall()
    {
        $library = new LibraryInstaller($this->dir, $this->dm, $this->registry);
        $package = $this->createPackageMock();

        $package
            ->expects($this->once())
            ->method('getName')
            ->will($this->returnValue('some/package'));

        $this->dm
            ->expects($this->once())
            ->method('download')
            ->with($package, $this->dir.'/some/package')
            ->will($this->returnValue('source'));

        $this->registry
            ->expects($this->once())
            ->method('registerPackage')
            ->with($package, 'source');

        $library->install($package);
    }

    public function testUpdate()
    {
        $library = new LibraryInstaller($this->dir, $this->dm, $this->registry);
        $initial = $this->createPackageMock();
        $target  = $this->createPackageMock();

        $initial
            ->expects($this->once())
            ->method('getName')
            ->will($this->returnValue('package1'));

        $this->registry
            ->expects($this->exactly(2))
            ->method('isPackageRegistered')
            ->with($initial)
            ->will($this->onConsecutiveCalls(true, false));

        $this->registry
            ->expects($this->once())
            ->method('getRegisteredPackageInstallerType')
            ->with($initial)
            ->will($this->returnValue('dist'));

        $this->dm
            ->expects($this->once())
            ->method('update')
            ->with($initial, $target, $this->dir.'/package1', 'dist');

        $this->registry
            ->expects($this->once())
            ->method('unregisterPackage')
            ->with($initial);

        $this->registry
            ->expects($this->once())
            ->method('registerPackage')
            ->with($target, 'dist');

        $library->update($initial, $target);

        $this->setExpectedException('UnexpectedValueException');

        $library->update($initial, $target);
    }

    public function testUninstall()
    {
        $library = new LibraryInstaller($this->dir, $this->dm, $this->registry);
        $package = $this->createPackageMock();

        $package
            ->expects($this->once())
            ->method('getName')
            ->will($this->returnValue('pkg'));

        $this->registry
            ->expects($this->exactly(2))
            ->method('isPackageRegistered')
            ->with($package)
            ->will($this->onConsecutiveCalls(true, false));

        $this->registry
            ->expects($this->once())
            ->method('getRegisteredPackageInstallerType')
            ->with($package)
            ->will($this->returnValue('source'));

        $this->dm
            ->expects($this->once())
            ->method('remove')
            ->with($package, $this->dir.'/pkg', 'source');

        $this->registry
            ->expects($this->once())
            ->method('unregisterPackage')
            ->with($package);

        $library->uninstall($package);

        $this->setExpectedException('UnexpectedValueException');

        $library->uninstall($package);
    }

    private function createPackageMock()
    {
        return $this->getMockBuilder('Composer\Package\MemoryPackage')
            ->setConstructorArgs(array(md5(rand()), '1.0.0'))
            ->getMock();
    }
}
