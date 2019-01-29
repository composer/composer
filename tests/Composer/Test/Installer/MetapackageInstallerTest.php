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

use Composer\Installer\MetapackageInstaller;
use PHPUnit\Framework\TestCase;

class MetapackageInstallerTest extends TestCase
{
    private $repository;
    private $installer;
    private $io;

    protected function setUp()
    {
        $this->repository = $this->getMockBuilder('Composer\Repository\InstalledRepositoryInterface')->getMock();

        $this->io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();

        $this->installer = new MetapackageInstaller($this->io);
    }

    public function testInstall()
    {
        $package = $this->createPackageMock();

        $this->repository
            ->expects($this->once())
            ->method('addPackage')
            ->with($package);

        $this->installer->install($this->repository, $package);
    }

    public function testUpdate()
    {
        $initial = $this->createPackageMock();
        $initial->expects($this->once())
            ->method('getVersion')
            ->will($this->returnValue('1.0.0'));
        $target = $this->createPackageMock();
        $target->expects($this->once())
            ->method('getVersion')
            ->will($this->returnValue('1.0.1'));

        $this->repository
            ->expects($this->exactly(2))
            ->method('hasPackage')
            ->with($initial)
            ->will($this->onConsecutiveCalls(true, false));

        $this->repository
            ->expects($this->once())
            ->method('removePackage')
            ->with($initial);

        $this->repository
            ->expects($this->once())
            ->method('addPackage')
            ->with($target);

        $this->installer->update($this->repository, $initial, $target);

        $this->setExpectedException('InvalidArgumentException');

        $this->installer->update($this->repository, $initial, $target);
    }

    public function testUninstall()
    {
        $package = $this->createPackageMock();

        $this->repository
            ->expects($this->exactly(2))
            ->method('hasPackage')
            ->with($package)
            ->will($this->onConsecutiveCalls(true, false));

        $this->repository
            ->expects($this->once())
            ->method('removePackage')
            ->with($package);

        $this->installer->uninstall($this->repository, $package);

        $this->setExpectedException('InvalidArgumentException');

        $this->installer->uninstall($this->repository, $package);
    }

    private function createPackageMock()
    {
        return $this->getMockBuilder('Composer\Package\Package')
            ->setConstructorArgs(array(md5(mt_rand()), '1.0.0.0', '1.0.0'))
            ->getMock();
    }
}
