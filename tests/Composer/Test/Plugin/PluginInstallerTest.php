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

        $this->repository = $this->getMock('Composer\Repository\InstalledRepositoryInterface');

        $rm = $this->getMockBuilder('Composer\Repository\RepositoryManager')
            ->disableOriginalConstructor()
            ->getMock();
        $rm->expects($this->any())
            ->method('getLocalRepository')
            ->will($this->returnValue($this->repository));

        $this->io = $this->getMock('Composer\IO\IOInterface');

        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')->disableOriginalConstructor()->getMock();
        $this->autoloadGenerator = new AutoloadGenerator($dispatcher);

        $this->composer = new Composer();
        $config = new Config();
        $this->composer->setConfig($config);
        $this->composer->setDownloadManager($dm);
        $this->composer->setRepositoryManager($rm);
        $this->composer->setAutoloadGenerator($this->autoloadGenerator);

        $this->pm = new \Composer\Plugin\PluginManager($this->composer, $this->io);
        $this->composer->setPluginManager($this->pm);

        $config->merge(array(
            'config' => array(
                'vendor-dir' => __DIR__.'/Fixtures/',
                'home' => __DIR__.'/Fixtures',
                'bin-dir' => __DIR__.'/Fixtures/bin',
            ),
        ));
    }

    public function testInstallNewPlugin()
    {
        $this->repository
            ->expects($this->exactly(2))
            ->method('getPackages')
            ->will($this->returnValue(array()));
        $installer = new PluginInstaller($this->io, $this->composer);
        $this->pm->loadInstalledPlugins();

        $installer->install($this->repository, $this->packages[0]);

        $plugins = $this->pm->getPlugins();
        $this->assertEquals('installer-v1', $plugins[0]->version);
    }

    public function testInstallMultiplePlugins()
    {
        $this->repository
            ->expects($this->exactly(2))
            ->method('getPackages')
            ->will($this->returnValue(array()));
        $installer = new PluginInstaller($this->io, $this->composer);
        $this->pm->loadInstalledPlugins();

        $installer->install($this->repository, $this->packages[3]);

        $plugins = $this->pm->getPlugins();
        $this->assertEquals('plugin1', $plugins[0]->name);
        $this->assertEquals('installer-v4', $plugins[0]->version);
        $this->assertEquals('plugin2', $plugins[1]->name);
        $this->assertEquals('installer-v4', $plugins[1]->version);
    }

    public function testUpgradeWithNewClassName()
    {
        $this->repository
            ->expects($this->exactly(3))
            ->method('getPackages')
            ->will($this->returnValue(array($this->packages[0])));
        $this->repository
            ->expects($this->exactly(2))
            ->method('hasPackage')
            ->will($this->onConsecutiveCalls(true, false));
        $installer = new PluginInstaller($this->io, $this->composer);
        $this->pm->loadInstalledPlugins();

        $installer->update($this->repository, $this->packages[0], $this->packages[1]);

        $plugins = $this->pm->getPlugins();
        $this->assertEquals('installer-v2', $plugins[1]->version);
    }

    public function testUpgradeWithSameClassName()
    {
        $this->repository
            ->expects($this->exactly(3))
            ->method('getPackages')
            ->will($this->returnValue(array($this->packages[1])));
        $this->repository
            ->expects($this->exactly(2))
            ->method('hasPackage')
            ->will($this->onConsecutiveCalls(true, false));
        $installer = new PluginInstaller($this->io, $this->composer);
        $this->pm->loadInstalledPlugins();

        $installer->update($this->repository, $this->packages[1], $this->packages[2]);

        $plugins = $this->pm->getPlugins();
        $this->assertEquals('installer-v3', $plugins[1]->version);
    }
}

