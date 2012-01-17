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

use Composer\Installer\InstallerInstaller;
use Composer\Package\Loader\JsonLoader;
use Composer\Package\PackageInterface;

class InstallerInstallerTest extends \PHPUnit_Framework_TestCase
{
    protected function setUp()
    {
        $loader = new JsonLoader();
        $this->packages = array();
        for ($i = 1; $i <= 3; $i++) {
            $this->packages[] = $loader->load(__DIR__.'/Fixtures/installer-v'.$i.'/composer.json');
        }

        $this->dm = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->disableOriginalConstructor()
            ->getMock();

        $this->im = $this->getMockBuilder('Composer\Installer\InstallationManager')
            ->disableOriginalConstructor()
            ->getMock();

        $this->repository = $this->getMockBuilder('Composer\Repository\WritableRepositoryInterface')
            ->getMock();

        $this->io = $this->getMockBuilder('Composer\IO\IOInterface')
            ->getMock();
    }

    public function testInstallNewInstaller()
    {
        $this->repository
            ->expects($this->once())
            ->method('getPackages')
            ->will($this->returnValue(array()));
        $installer = new InstallerInstallerMock(__DIR__.'/Fixtures/', __DIR__.'/Fixtures/bin', $this->dm, $this->repository, $this->io, $this->im);

        $test = $this;
        $this->im
            ->expects($this->once())
            ->method('addInstaller')
            ->will($this->returnCallback(function ($installer) use ($test) {
                $test->assertEquals('installer-v1', $installer->version);
            }));

        $installer->install($this->packages[0]);
    }

    public function testUpgradeWithNewClassName()
    {
        $this->repository
            ->expects($this->once())
            ->method('getPackages')
            ->will($this->returnValue(array($this->packages[0])));
        $this->repository
            ->expects($this->once())
            ->method('hasPackage')
            ->will($this->returnValue(true));
        $installer = new InstallerInstallerMock(__DIR__.'/Fixtures/', __DIR__.'/Fixtures/bin', $this->dm, $this->repository, $this->io, $this->im);

        $test = $this;
        $this->im
            ->expects($this->once())
            ->method('addInstaller')
            ->will($this->returnCallback(function ($installer) use ($test) {
                $test->assertEquals('installer-v2', $installer->version);
            }));

        $installer->update($this->packages[0], $this->packages[1]);
    }

    public function testUpgradeWithSameClassName()
    {
        $this->repository
            ->expects($this->once())
            ->method('getPackages')
            ->will($this->returnValue(array($this->packages[1])));
        $this->repository
            ->expects($this->once())
            ->method('hasPackage')
            ->will($this->returnValue(true));
        $installer = new InstallerInstallerMock(__DIR__.'/Fixtures/', __DIR__.'/Fixtures/bin', $this->dm, $this->repository, $this->io, $this->im);

        $test = $this;
        $this->im
            ->expects($this->once())
            ->method('addInstaller')
            ->will($this->returnCallback(function ($installer) use ($test) {
                $test->assertEquals('installer-v3', $installer->version);
            }));

        $installer->update($this->packages[1], $this->packages[2]);
    }
}

class InstallerInstallerMock extends InstallerInstaller
{
    public function getInstallPath(PackageInterface $package)
    {
        $version = $package->getVersion();
        return __DIR__.'/Fixtures/installer-v'.$version[0].'/';
    }
}