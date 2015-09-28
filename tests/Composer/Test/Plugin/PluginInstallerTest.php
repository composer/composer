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
use Composer\Package\CompletePackage;
use Composer\Package\Loader\JsonLoader;
use Composer\Package\Loader\ArrayLoader;
use Composer\Plugin\PluginManager;
use Composer\Autoload\AutoloadGenerator;
use Composer\TestCase;
use Composer\Util\Filesystem;

class PluginInstallerTest extends TestCase
{
    /**
     * @var Composer
     */
    protected $composer;

    /**
     * @var PluginManager
     */
    protected $pm;

    /**
     * @var AutoloadGenerator
     */
    protected $autoloadGenerator;

    /**
     * @var CompletePackage[]
     */
    protected $packages;

    /**
     * @var string
     */
    protected $directory;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $im;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $repository;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $io;

    protected function setUp()
    {
        $loader = new JsonLoader(new ArrayLoader());
        $this->packages = array();
        $this->directory = sys_get_temp_dir() . '/' . uniqid();
        for ($i = 1; $i <= 7; $i++) {
            $filename = '/Fixtures/plugin-v'.$i.'/composer.json';
            mkdir(dirname($this->directory . $filename), 0777, true);
            $this->packages[] = $loader->load(__DIR__ . $filename);
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

        $im = $this->getMock('Composer\Installer\InstallationManager');
        $im->expects($this->any())
            ->method('getInstallPath')
            ->will($this->returnCallback(function ($package) {
                return __DIR__.'/Fixtures/'.$package->getPrettyName();
            }));

        $this->io = $this->getMock('Composer\IO\IOInterface');

        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')->disableOriginalConstructor()->getMock();
        $this->autoloadGenerator = new AutoloadGenerator($dispatcher);

        $this->composer = new Composer();
        $config = new Config();
        $this->composer->setConfig($config);
        $this->composer->setDownloadManager($dm);
        $this->composer->setRepositoryManager($rm);
        $this->composer->setInstallationManager($im);
        $this->composer->setAutoloadGenerator($this->autoloadGenerator);

        $this->pm = new PluginManager($this->io, $this->composer);
        $this->composer->setPluginManager($this->pm);

        $config->merge(array(
            'config' => array(
                'vendor-dir' => $this->directory.'/Fixtures/',
                'home' => $this->directory.'/Fixtures',
                'bin-dir' => $this->directory.'/Fixtures/bin',
            ),
        ));
    }

    protected function tearDown()
    {
        $filesystem = new Filesystem();
        $filesystem->removeDirectory($this->directory);
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

    public function testRegisterPluginOnlyOneTime()
    {
        $this->repository
            ->expects($this->exactly(2))
            ->method('getPackages')
            ->will($this->returnValue(array()));
        $installer = new PluginInstaller($this->io, $this->composer);
        $this->pm->loadInstalledPlugins();

        $installer->install($this->repository, $this->packages[0]);
        $installer->install($this->repository, clone $this->packages[0]);

        $plugins = $this->pm->getPlugins();
        $this->assertCount(1, $plugins);
        $this->assertEquals('installer-v1', $plugins[0]->version);
    }

    /**
     * @param string            $newPluginApiVersion
     * @param CompletePackage[] $plugins
     */
    private function setPluginApiVersionWithPlugins($newPluginApiVersion, array $plugins = array())
    {
        // reset the plugin manager's installed plugins
        $this->pm = $this->getMockBuilder('Composer\Plugin\PluginManager')
                         ->setMethods(array('getPluginApiVersion'))
                         ->setConstructorArgs(array($this->io, $this->composer))
                         ->getMock();

        // mock the Plugin API version
        $this->pm->expects($this->any())
                 ->method('getPluginApiVersion')
                 ->will($this->returnValue($newPluginApiVersion));

        $plugApiInternalPackage = $this->getPackage(
            'composer-plugin-api',
            $newPluginApiVersion,
            'Composer\Package\CompletePackage'
        );

        // Add the plugins to the repo along with the internal Plugin package on which they all rely.
        $this->repository
             ->expects($this->any())
             ->method('getPackages')
             ->will($this->returnCallback(function () use ($plugApiInternalPackage, $plugins) {
                return array_merge(array($plugApiInternalPackage), $plugins);
             }));

        $this->pm->loadInstalledPlugins();
    }

    public function testExactPluginVersionStyleAreRegisteredCorrectly()
    {
        $pluginsWithFixedAPIVersions = array(
            $this->packages[0],
            $this->packages[1],
            $this->packages[2],
        );

        $this->setPluginApiVersionWithPlugins('1.0.0', $pluginsWithFixedAPIVersions);
        $this->assertCount(3, $this->pm->getPlugins());

        $this->setPluginApiVersionWithPlugins('1.0.1', $pluginsWithFixedAPIVersions);
        $this->assertCount(0, $this->pm->getPlugins());

        $this->setPluginApiVersionWithPlugins('2.0.0-dev', $pluginsWithFixedAPIVersions);
        $this->assertCount(0, $this->pm->getPlugins());
    }

    public function testStarPluginVersionWorksWithAnyAPIVersion()
    {
        $starVersionPlugin = array($this->packages[4]);

        $this->setPluginApiVersionWithPlugins('1.0.0', $starVersionPlugin);
        $this->assertCount(1, $this->pm->getPlugins());

        $this->setPluginApiVersionWithPlugins('1.9.9', $starVersionPlugin);
        $this->assertCount(1, $this->pm->getPlugins());

        $this->setPluginApiVersionWithPlugins('2.0.0-dev', $starVersionPlugin);
        $this->assertCount(1, $this->pm->getPlugins());

        $this->setPluginApiVersionWithPlugins('100.0.0-stable', $starVersionPlugin);
        $this->assertCount(1, $this->pm->getPlugins());
    }

    public function testPluginConstraintWorksOnlyWithCertainAPIVersion()
    {
        $pluginWithApiConstraint = array($this->packages[5]);

        $this->setPluginApiVersionWithPlugins('1.0.0', $pluginWithApiConstraint);
        $this->assertCount(0, $this->pm->getPlugins());

        $this->setPluginApiVersionWithPlugins('1.1.9', $pluginWithApiConstraint);
        $this->assertCount(0, $this->pm->getPlugins());

        $this->setPluginApiVersionWithPlugins('1.2.0', $pluginWithApiConstraint);
        $this->assertCount(1, $this->pm->getPlugins());

        $this->setPluginApiVersionWithPlugins('1.9.9', $pluginWithApiConstraint);
        $this->assertCount(1, $this->pm->getPlugins());
    }

    public function testPluginRangeConstraintsWorkOnlyWithCertainAPIVersion()
    {
        $pluginWithApiConstraint = array($this->packages[6]);

        $this->setPluginApiVersionWithPlugins('1.0.0', $pluginWithApiConstraint);
        $this->assertCount(0, $this->pm->getPlugins());

        $this->setPluginApiVersionWithPlugins('3.0.0', $pluginWithApiConstraint);
        $this->assertCount(1, $this->pm->getPlugins());

        $this->setPluginApiVersionWithPlugins('5.5.0', $pluginWithApiConstraint);
        $this->assertCount(0, $this->pm->getPlugins());
    }
}
