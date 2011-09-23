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

namespace Composer\Test\Installer\Registry;

use Composer\Installer\Registry\FilesystemRegistry;

class FilesystemRegistryTest extends \PHPUnit_Framework_TestCase
{
    private $dir;
    private $registryFile;

    protected function setUp()
    {
        $this->dir = sys_get_temp_dir().'/.composer';
        $this->registryFile = $this->dir.'/some_registry-reg.json';

        if (file_exists($this->registryFile)) {
            unlink($this->registryFile);
        }
    }

    public function testRegistryCreation()
    {
        $this->assertFileNotExists($this->registryFile);
        $registry = new FilesystemRegistry($this->dir, 'some_registry');

        $registry->open();
        $registry->close();
        $this->assertFileExists($this->registryFile);

        file_put_contents($this->registryFile, json_encode(array(
            'package1-1.0.0-beta' => 'library'
        )));

        $registry->open();
        $registry->close();
        $this->assertFileExists($this->registryFile);

        $data = json_decode(file_get_contents($this->registryFile), true);
        $this->assertEquals(array('package1-1.0.0-beta' => 'library'), $data);
    }

    public function testIsPackageRegistered()
    {
        file_put_contents($this->registryFile, json_encode(array(
            'package1-1.0.0-beta' => 'library'
        )));

        $registry = new FilesystemRegistry($this->dir, 'some_registry');
        $registry->open();

        $package1 = $this->createPackageMock();
        $package1
            ->expects($this->once())
            ->method('getUniqueName')
            ->will($this->returnValue('package1-1.0.0-beta'));
        $package2 = $this->createPackageMock();
        $package2
            ->expects($this->once())
            ->method('getUniqueName')
            ->will($this->returnValue('package2-1.1.0-stable'));

        $this->assertTrue($registry->isPackageRegistered($package1));
        $this->assertFalse($registry->isPackageRegistered($package2));

        $registry->close();
    }

    public function testGetRegisteredPackageInstallerType()
    {
        $package1 = $this->createPackageMock();
        $package1
            ->expects($this->once())
            ->method('getUniqueName')
            ->will($this->returnValue('package1-1.0.0-beta'));
        $package2 = $this->createPackageMock();
        $package2
            ->expects($this->once())
            ->method('getUniqueName')
            ->will($this->returnValue('package2-1.1.0-stable'));

        file_put_contents($this->registryFile, json_encode(array(
            'package1-1.0.0-beta'   => 'library',
            'package2-1.1.0-stable' => 'bundle'
        )));

        $registry = new FilesystemRegistry($this->dir, 'some_registry');
        $registry->open();

        $this->assertSame('library', $registry->getRegisteredPackageInstallerType($package1));
        $this->assertSame('bundle', $registry->getRegisteredPackageInstallerType($package2));

        $registry->close();
    }

    public function testRegisterPackage()
    {
        $package = $this->createPackageMock();
        $package
            ->expects($this->once())
            ->method('getUniqueName')
            ->will($this->returnValue('package1-1.0.0-beta'));

        $registry = new FilesystemRegistry($this->dir, 'some_registry');
        $registry->open();

        $registry->registerPackage($package, 'library');

        $registry->close();

        $data = json_decode(file_get_contents($this->registryFile), true);

        $this->assertEquals(array('package1-1.0.0-beta' => 'library'), $data);
    }

    public function testUnregisterPackage()
    {
        $package = $this->createPackageMock();
        $package
            ->expects($this->once())
            ->method('getUniqueName')
            ->will($this->returnValue('package1-1.0.0-beta'));

        file_put_contents($this->registryFile, json_encode(array(
            'package1-1.0.0-beta'   => 'library',
            'package2-1.1.0-stable' => 'bundle'
        )));

        $registry = new FilesystemRegistry($this->dir, 'some_registry');
        $registry->open();

        $registry->unregisterPackage($package);

        $registry->close();

        $data = json_decode(file_get_contents($this->registryFile), true);

        $this->assertEquals(array('package2-1.1.0-stable' => 'bundle'), $data);
    }

    private function createPackageMock()
    {
        return $this->getMockBuilder('Composer\Package\PackageInterface')
            ->getMock();
    }
}
