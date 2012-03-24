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

class MetapackageInstallerTest extends \PHPUnit_Framework_TestCase
{
    private $repository;
    private $installer;
    private $io;

    protected function setUp()
    {
        $this->repository = $this->getMock('Composer\Repository\WritableRepositoryInterface');

        $this->io = $this->getMock('Composer\IO\IOInterface');

        $this->installer = new MetapackageInstaller($this->repository, $this->io);
    }

    public function testInstall()
    {
        $package = $this->createPackageMock();

        $this->repository
            ->expects($this->once())
            ->method('addPackage')
            ->with($package);

        $this->installer->install($package);
    }

    public function testUpdate()
    {
        $initial = $this->createPackageMock();
        $target  = $this->createPackageMock();

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

        $this->installer->update($initial, $target);

        $this->setExpectedException('InvalidArgumentException');

        $this->installer->update($initial, $target);
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

        $this->installer->uninstall($package);

        // TODO re-enable once #125 is fixed and we throw exceptions again
//        $this->setExpectedException('InvalidArgumentException');

        $this->installer->uninstall($package);
    }

    private function createPackageMock()
    {
        return $this->getMockBuilder('Composer\Package\MemoryPackage')
            ->setConstructorArgs(array(md5(rand()), '1.0.0.0', '1.0.0'))
            ->getMock();
    }
}
