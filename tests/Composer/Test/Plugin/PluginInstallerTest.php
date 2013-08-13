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

use Composer\Composer;
use Composer\Config;
use Composer\Installer\PluginInstaller;
use Composer\Package\Loader\JsonLoader;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\PackageInterface;
use Composer\Autoload\AutoloadGenerator;

class PluginInstallerTest extends \PHPUnit_Framework_TestCase
{
    protected $composer;
    protected $packages;
    protected $im;
    protected $pm;
    protected $repository;
    protected $io;
    protected $autoloadGenerator;

    protected function setUp()
    {
        $loader = new JsonLoader(new ArrayLoader());
        $this->packages = array();
        for ($i = 1; $i <= 4; $i++) {
            $this->packages[] = $loader->load(__DIR__.'/Fixtures/plugin-v'.$i.'/composer.json');
        }

        $dm = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->disableOriginalConstructor()
            ->getMock();

        $this->im = $this->getMockBuilder('Composer\Installer\InstallationManager')
            ->disableOriginalConstructor()
            ->getMock();

        $this->pm = $this->getMockBuilder('Composer\Plugin\PluginManager')
            ->disableOriginalConstructor()
            ->getMock();

        $this->repository = $this->getMock('Composer\Repository\InstalledRepositoryInterface');

        $rm = $this->getMockBuilder('Composer\Repository\RepositoryManager')
            ->disableOriginalConstructor()
            ->getMock();
        $rm->expects($this->any())
            ->method('getLocalRepository')
            ->will($this->returnValue($this->repository));

        $this->io = $this->getMock('Composer\IO\IOInterface');

        $dispatcher = $this->getMockBuilder('Composer\Script\EventDispatcher')->disableOriginalConstructor()->getMock();
        $this->autoloadGenerator = new AutoloadGenerator($dispatcher);

        $this->composer = new Composer();
        $config = new Config();
        $this->composer->setConfig($config);
        $this->composer->setDownloadManager($dm);
        $this->composer->setInstallationManager($this->im);
        $this->composer->setPluginManager($this->pm);
        $this->composer->setRepositoryManager($rm);
        $this->composer->setAutoloadGenerator($this->autoloadGenerator);

        $config->merge(array(
            'config' => array(
                'vendor-dir' => __DIR__.'/Fixtures/',
                'bin-dir' => __DIR__.'/Fixtures/bin',
            ),
        ));
    }

    public function testInstallNewPlugin()
    {
        $this->repository
            ->expects($this->once())
            ->method('getPackages')
            ->will($this->returnValue(array()));
        $installer = new PluginInstallerMock($this->io, $this->composer);

        $test = $this;
        $this->pm
            ->expects($this->once())
            ->method('addPlugin')
            ->will($this->returnCallback(function ($installer) use ($test) {
                $test->assertEquals('installer-v1', $installer->version);
            }));

        $installer->install($this->repository, $this->packages[0]);
    }

    public function testInstallMultiplePlugins()
    {
        $this->repository
            ->expects($this->once())
            ->method('getPackages')
            ->will($this->returnValue(array()));

        $installer = new PluginInstallerMock($this->io, $this->composer);

        $test = $this;

        $this->pm
            ->expects($this->at(0))
            ->method('addPlugin')
            ->will($this->returnCallback(function ($plugin) use ($test) {
                $test->assertEquals('plugin1', $plugin->name);
                $test->assertEquals('installer-v4', $plugin->version);
            }));

        $this->pm
            ->expects($this->at(1))
            ->method('addPlugin')
            ->will($this->returnCallback(function ($plugin) use ($test) {
                $test->assertEquals('plugin2', $plugin->name);
                $test->assertEquals('installer-v4', $plugin->version);
            }));

        $installer->install($this->repository, $this->packages[3]);
    }

    public function testUpgradeWithNewClassName()
    {
        $this->repository
            ->expects($this->once())
            ->method('getPackages')
            ->will($this->returnValue(array($this->packages[0])));
        $this->repository
            ->expects($this->exactly(2))
            ->method('hasPackage')
            ->will($this->onConsecutiveCalls(true, false));
        $installer = new PluginInstallerMock($this->io, $this->composer);

        $test = $this;
        $this->pm
            ->expects($this->once())
            ->method('addPlugin')
            ->will($this->returnCallback(function ($plugin) use ($test) {
                $test->assertEquals('installer-v2', $plugin->version);
            }));

        $installer->update($this->repository, $this->packages[0], $this->packages[1]);
    }

    public function testUpgradeWithSameClassName()
    {
        $this->repository
            ->expects($this->once())
            ->method('getPackages')
            ->will($this->returnValue(array($this->packages[1])));
        $this->repository
            ->expects($this->exactly(2))
            ->method('hasPackage')
            ->will($this->onConsecutiveCalls(true, false));
        $installer = new PluginInstallerMock($this->io, $this->composer);

        $test = $this;
        $this->pm
            ->expects($this->once())
            ->method('addPlugin')
            ->will($this->returnCallback(function ($plugin) use ($test) {
                $test->assertEquals('installer-v3', $plugin->version);
            }));

        $installer->update($this->repository, $this->packages[1], $this->packages[2]);
    }
}

class PluginInstallerMock extends PluginInstaller
{
    public function getInstallPath(PackageInterface $package)
    {
        $version = $package->getVersion();

        return __DIR__.'/Fixtures/plugin-v'.$version[0].'/';
    }
}
