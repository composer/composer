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
    private $repository;
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

        $this->repository = $this->getMockBuilder('Composer\Repository\WritableRepositoryInterface')
            ->disableOriginalConstructor()
            ->getMock();
    }

    public function testInstallerCreation()
    {
        $library = new LibraryInstaller($this->dir, $this->dm, $this->repository);
        $this->assertTrue(is_dir($this->dir));

        $file = sys_get_temp_dir().'/file';
        touch($file);

        $this->setExpectedException('UnexpectedValueException');
        $library = new LibraryInstaller($file, $this->dm, $this->repository);
    }

    public function testIsInstalled()
    {
        $library = new LibraryInstaller($this->dir, $this->dm, $this->repository);
        $package = $this->createPackageMock();

        $this->repository
            ->expects($this->exactly(2))
            ->method('hasPackage')
            ->with($package)
            ->will($this->onConsecutiveCalls(true, false));

        $this->assertTrue($library->isInstalled($package));
        $this->assertFalse($library->isInstalled($package));
    }

    public function testInstall()
    {
        $library = new LibraryInstaller($this->dir, $this->dm, $this->repository);
        $package = $this->createPackageMock();

        $package
            ->expects($this->once())
            ->method('getName')
            ->will($this->returnValue('some/package'));

        $this->dm
            ->expects($this->once())
            ->method('download')
            ->with($package, $this->dir.'/some/package');

        $this->repository
            ->expects($this->once())
            ->method('addPackage')
            ->with($package);

        $library->install($package);
    }

    public function testUpdate()
    {
        $library = new LibraryInstaller($this->dir, $this->dm, $this->repository);
        $initial = $this->createPackageMock();
        $target  = $this->createPackageMock();

        $initial
            ->expects($this->once())
            ->method('getName')
            ->will($this->returnValue('package1'));

        $this->repository
            ->expects($this->exactly(2))
            ->method('hasPackage')
            ->with($initial)
            ->will($this->onConsecutiveCalls(true, false));

        $this->dm
            ->expects($this->once())
            ->method('update')
            ->with($initial, $target, $this->dir.'/package1');

        $this->repository
            ->expects($this->once())
            ->method('removePackage')
            ->with($initial);

        $this->repository
            ->expects($this->once())
            ->method('addPackage')
            ->with($target);

        $library->update($initial, $target);

        $this->setExpectedException('InvalidArgumentException');

        $library->update($initial, $target);
    }

    public function testUninstall()
    {
        $library = new LibraryInstaller($this->dir, $this->dm, $this->repository);
        $package = $this->createPackageMock();

        $package
            ->expects($this->once())
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
            ->with($package, $this->dir.'/pkg');

        $this->repository
            ->expects($this->once())
            ->method('removePackage')
            ->with($package);

        $library->uninstall($package);

        // TODO re-enable once #125 is fixed and we throw exceptions again
//        $this->setExpectedException('InvalidArgumentException');

        $library->uninstall($package);
    }

    public function testGetInstallPath()
    {
        $library = new LibraryInstaller($this->dir, $this->dm, $this->repository);
        $package = $this->createPackageMock();

        $package
            ->expects($this->once())
            ->method('getTargetDir')
            ->will($this->returnValue(null));

        $this->assertEquals($this->dir.'/'.$package->getName(), $library->getInstallPath($package));
    }

    public function testGetInstallPathWithTargetDir()
    {
        $library = new LibraryInstaller($this->dir, $this->dm, $this->repository);
        $package = $this->createPackageMock();

        $package
            ->expects($this->once())
            ->method('getTargetDir')
            ->will($this->returnValue('Some/Namespace'));

        $this->assertEquals($this->dir.'/'.$package->getName().'/Some/Namespace', $library->getInstallPath($package));
    }

    private function createPackageMock()
    {
        return $this->getMockBuilder('Composer\Package\MemoryPackage')
            ->setConstructorArgs(array(md5(rand()), '1.0.0.0', '1.0.0'))
            ->getMock();
    }
}
