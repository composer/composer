<?php declare(strict_types=1);

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test\Plugin;

use Composer\Composer;
use Composer\Config;
use Composer\Installer\PluginInstaller;
use Composer\Package\CompleteAliasPackage;
use Composer\Package\CompletePackage;
use Composer\Package\Loader\JsonLoader;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\RootPackage;
use Composer\Plugin\PluginManager;
use Composer\IO\BufferIO;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Autoload\AutoloadGenerator;
use Composer\Test\TestCase;
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
     * @var array<CompletePackage|CompleteAliasPackage>
     */
    protected $packages;

    /**
     * @var string
     */
    protected $directory;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject&\Composer\Installer\InstallationManager
     */
    protected $im;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject&\Composer\Repository\InstalledRepositoryInterface
     */
    protected $repository;

    /**
     * @var BufferIO
     */
    protected $io;

    protected function setUp(): void
    {
        $loader = new JsonLoader(new ArrayLoader());
        $this->packages = array();
        $this->directory = self::getUniqueTmpDirectory();
        for ($i = 1; $i <= 8; $i++) {
            $filename = '/Fixtures/plugin-v'.$i.'/composer.json';
            mkdir(dirname($this->directory . $filename), 0777, true);
            $this->packages[] = $loader->load(__DIR__ . $filename);
        }

        $dm = $this->getMockBuilder('Composer\Downloader\DownloadManager')
            ->disableOriginalConstructor()
            ->getMock();
        $dm->expects($this->any())
            ->method('install')
            ->will($this->returnValue(\React\Promise\resolve(null)));
        $dm->expects($this->any())
            ->method('update')
            ->will($this->returnValue(\React\Promise\resolve(null)));
        $dm->expects($this->any())
            ->method('remove')
            ->will($this->returnValue(\React\Promise\resolve(null)));

        $this->repository = $this->getMockBuilder('Composer\Repository\InstalledRepositoryInterface')->getMock();

        $rm = $this->getMockBuilder('Composer\Repository\RepositoryManager')
            ->disableOriginalConstructor()
            ->getMock();
        $rm->expects($this->any())
            ->method('getLocalRepository')
            ->will($this->returnValue($this->repository));

        $im = $this->getMockBuilder('Composer\Installer\InstallationManager')->disableOriginalConstructor()->getMock();
        $im->expects($this->any())
            ->method('getInstallPath')
            ->will($this->returnCallback(function ($package): string {
                return __DIR__.'/Fixtures/'.$package->getPrettyName();
            }));

        $this->io = new BufferIO();

        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')->disableOriginalConstructor()->getMock();
        $this->autoloadGenerator = new AutoloadGenerator($dispatcher);

        $this->composer = new Composer();
        $config = new Config(false);
        $this->composer->setConfig($config);
        $this->composer->setDownloadManager($dm);
        $this->composer->setRepositoryManager($rm);
        $this->composer->setInstallationManager($im);
        $this->composer->setAutoloadGenerator($this->autoloadGenerator);
        $this->composer->setEventDispatcher(new EventDispatcher($this->composer, $this->io));
        $this->composer->setPackage(new RootPackage('dummy/root', '1.0.0.0', '1.0.0'));

        $config->merge(array(
            'config' => array(
                'vendor-dir' => $this->directory.'/Fixtures/',
                'home' => $this->directory.'/Fixtures',
                'bin-dir' => $this->directory.'/Fixtures/bin',
                'allow-plugins' => true,
            ),
        ));

        $this->pm = new PluginManager($this->io, $this->composer);
        $this->composer->setPluginManager($this->pm);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $filesystem = new Filesystem();
        $filesystem->removeDirectory($this->directory);
    }

    public function testInstallNewPlugin(): void
    {
        $this->repository
            ->expects($this->any())
            ->method('getPackages')
            ->will($this->returnValue(array()));
        $installer = new PluginInstaller($this->io, $this->composer);
        $this->pm->loadInstalledPlugins();

        $installer->install($this->repository, $this->packages[0]);

        $plugins = $this->pm->getPlugins();
        $this->assertEquals('installer-v1', $plugins[0]->version);  // @phpstan-ignore-line
        $this->assertEquals(
            'activate v1'.PHP_EOL,
            $this->io->getOutput()
        );
    }

    public function testInstallPluginWithRootPackageHavingFilesAutoload(): void
    {
        $this->repository
            ->expects($this->any())
            ->method('getPackages')
            ->will($this->returnValue(array()));
        $installer = new PluginInstaller($this->io, $this->composer);
        $this->pm->loadInstalledPlugins();

        $this->autoloadGenerator->setDevMode(true);
        $this->composer->getPackage()->setAutoload(array('files' => array(__DIR__ . '/Fixtures/files_autoload_which_should_not_run.php')));
        $this->composer->getPackage()->setDevAutoload(array('files' => array(__DIR__ . '/Fixtures/files_autoload_which_should_not_run.php')));
        $installer->install($this->repository, $this->packages[0]);

        $plugins = $this->pm->getPlugins();
        $this->assertEquals(
            'activate v1'.PHP_EOL,
            $this->io->getOutput()
        );
        $this->assertEquals('installer-v1', $plugins[0]->version);  // @phpstan-ignore-line
    }

    public function testInstallMultiplePlugins(): void
    {
        $this->repository
            ->expects($this->any())
            ->method('getPackages')
            ->will($this->returnValue(array($this->packages[3])));
        $installer = new PluginInstaller($this->io, $this->composer);
        $this->pm->loadInstalledPlugins();

        $installer->install($this->repository, $this->packages[3]);

        $plugins = $this->pm->getPlugins();
        $this->assertEquals('plugin1', $plugins[0]->name); // @phpstan-ignore-line
        $this->assertEquals('installer-v4', $plugins[0]->version); // @phpstan-ignore-line
        $this->assertEquals('plugin2', $plugins[1]->name); // @phpstan-ignore-line
        $this->assertEquals('installer-v4', $plugins[1]->version); // @phpstan-ignore-line
        $this->assertEquals('activate v4-plugin1'.PHP_EOL.'activate v4-plugin2'.PHP_EOL, $this->io->getOutput());
    }

    public function testUpgradeWithNewClassName(): void
    {
        $this->repository
            ->expects($this->any())
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
        $this->assertCount(1, $plugins);
        $this->assertEquals('installer-v2', $plugins[1]->version); // @phpstan-ignore-line
        $this->assertEquals('activate v1'.PHP_EOL.'deactivate v1'.PHP_EOL.'activate v2'.PHP_EOL, $this->io->getOutput());
    }

    public function testUninstall(): void
    {
        $this->repository
            ->expects($this->any())
            ->method('getPackages')
            ->will($this->returnValue(array($this->packages[0])));
        $this->repository
            ->expects($this->exactly(1))
            ->method('hasPackage')
            ->will($this->onConsecutiveCalls(true, false));
        $installer = new PluginInstaller($this->io, $this->composer);
        $this->pm->loadInstalledPlugins();

        $installer->uninstall($this->repository, $this->packages[0]);

        $plugins = $this->pm->getPlugins();
        $this->assertCount(0, $plugins);
        $this->assertEquals('activate v1'.PHP_EOL.'deactivate v1'.PHP_EOL.'uninstall v1'.PHP_EOL, $this->io->getOutput());
    }

    public function testUpgradeWithSameClassName(): void
    {
        $this->repository
            ->expects($this->any())
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
        $this->assertEquals('installer-v3', $plugins[1]->version); // @phpstan-ignore-line
        $this->assertEquals('activate v2'.PHP_EOL.'deactivate v2'.PHP_EOL.'activate v3'.PHP_EOL, $this->io->getOutput());
    }

    public function testRegisterPluginOnlyOneTime(): void
    {
        $this->repository
            ->expects($this->any())
            ->method('getPackages')
            ->will($this->returnValue(array()));
        $installer = new PluginInstaller($this->io, $this->composer);
        $this->pm->loadInstalledPlugins();

        $installer->install($this->repository, $this->packages[0]);
        $installer->install($this->repository, clone $this->packages[0]);

        $plugins = $this->pm->getPlugins();
        $this->assertCount(1, $plugins);
        $this->assertEquals('installer-v1', $plugins[0]->version); // @phpstan-ignore-line
        $this->assertEquals('activate v1'.PHP_EOL, $this->io->getOutput());
    }

    /**
     * @param string            $newPluginApiVersion
     * @param array<CompletePackage|CompleteAliasPackage> $plugins
     *
     * @return void
     */
    private function setPluginApiVersionWithPlugins(string $newPluginApiVersion, array $plugins = array()): void
    {
        // reset the plugin manager's installed plugins
        $this->pm = $this->getMockBuilder('Composer\Plugin\PluginManager')
                         ->onlyMethods(array('getPluginApiVersion'))
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
            ->will($this->returnCallback(function () use ($plugApiInternalPackage, $plugins): array {
                return array_merge(array($plugApiInternalPackage), $plugins);
            }));

        $this->pm->loadInstalledPlugins();
    }

    public function testStarPluginVersionWorksWithAnyAPIVersion(): void
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

    public function testPluginConstraintWorksOnlyWithCertainAPIVersion(): void
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

    public function testPluginRangeConstraintsWorkOnlyWithCertainAPIVersion(): void
    {
        $pluginWithApiConstraint = array($this->packages[6]);

        $this->setPluginApiVersionWithPlugins('1.0.0', $pluginWithApiConstraint);
        $this->assertCount(0, $this->pm->getPlugins());

        $this->setPluginApiVersionWithPlugins('3.0.0', $pluginWithApiConstraint);
        $this->assertCount(1, $this->pm->getPlugins());

        $this->setPluginApiVersionWithPlugins('5.5.0', $pluginWithApiConstraint);
        $this->assertCount(0, $this->pm->getPlugins());
    }

    public function testCommandProviderCapability(): void
    {
        $this->repository
            ->expects($this->any())
            ->method('getPackages')
            ->will($this->returnValue(array($this->packages[7])));
        $installer = new PluginInstaller($this->io, $this->composer);
        $this->pm->loadInstalledPlugins();

        /** @var \Composer\Plugin\Capability\CommandProvider[] $caps */
        $caps = $this->pm->getPluginCapabilities('Composer\Plugin\Capability\CommandProvider', array('composer' => $this->composer, 'io' => $this->io));
        $this->assertCount(1, $caps);
        $this->assertInstanceOf('Composer\Plugin\Capability\CommandProvider', $caps[0]);

        $commands = $caps[0]->getCommands();
        $this->assertCount(1, $commands);
        $this->assertInstanceOf('Composer\Command\BaseCommand', $commands[0]);
    }

    public function testIncapablePluginIsCorrectlyDetected(): void
    {
        $plugin = $this->getMockBuilder('Composer\Plugin\PluginInterface')
                       ->getMock();
        $this->assertNull($this->pm->getPluginCapability($plugin, 'Fake\Ability'));
    }

    public function testCapabilityImplementsComposerPluginApiClassAndIsConstructedWithArgs(): void
    {
        $capabilityApi = 'Composer\Plugin\Capability\Capability';
        $capabilityImplementation = 'Composer\Test\Plugin\Mock\Capability';

        $plugin = $this->getMockBuilder('Composer\Test\Plugin\Mock\CapablePluginInterface')
                       ->getMock();

        $plugin->expects($this->once())
               ->method('getCapabilities')
               ->will($this->returnCallback(function () use ($capabilityImplementation, $capabilityApi): array {
                   return array($capabilityApi => $capabilityImplementation);
               }));

        /** @var \Composer\Test\Plugin\Mock\Capability $capability */
        $capability = $this->pm->getPluginCapability($plugin, $capabilityApi, array('a' => 1, 'b' => 2));

        $this->assertInstanceOf($capabilityApi, $capability);
        $this->assertInstanceOf($capabilityImplementation, $capability);
        $this->assertSame(array('a' => 1, 'b' => 2, 'plugin' => $plugin), $capability->args);
    }

    /** @return mixed[] */
    public function invalidImplementationClassNames(): array
    {
        return array(
            array(null),
            array(""),
            array(0),
            array(1000),
            array("   "),
            array(array(1)),
            array(array()),
            array(new \stdClass()),
        );
    }

    /**
     * @dataProvider invalidImplementationClassNames
     * @param mixed $invalidImplementationClassNames
     * @param class-string<\Throwable> $expect
     */
    public function testQueryingWithInvalidCapabilityClassNameThrows($invalidImplementationClassNames, string $expect = 'UnexpectedValueException'): void
    {
        self::expectException($expect);

        $capabilityApi = 'Composer\Plugin\Capability\Capability';

        $plugin = $this->getMockBuilder('Composer\Test\Plugin\Mock\CapablePluginInterface')
                       ->getMock();

        $plugin->expects($this->once())
               ->method('getCapabilities')
               ->will($this->returnCallback(function () use ($invalidImplementationClassNames, $capabilityApi): array {
                   return array($capabilityApi => $invalidImplementationClassNames);
               }));

        $this->pm->getPluginCapability($plugin, $capabilityApi);
    }

    public function testQueryingNonProvidedCapabilityReturnsNullSafely(): void
    {
        $capabilityApi = 'Composer\Plugin\Capability\MadeUpCapability';

        $plugin = $this->getMockBuilder('Composer\Test\Plugin\Mock\CapablePluginInterface')
                       ->getMock();

        $plugin->expects($this->once())
               ->method('getCapabilities')
               ->will($this->returnCallback(function (): array {
                   return array();
               }));

        $this->assertNull($this->pm->getPluginCapability($plugin, $capabilityApi));
    }

    /** @return mixed[] */
    public function nonExistingOrInvalidImplementationClassTypes(): array
    {
        return array(
            array('\stdClass'),
            array('NonExistentClassLikeMiddleClass'),
        );
    }

    /**
     * @dataProvider nonExistingOrInvalidImplementationClassTypes
     */
    public function testQueryingWithNonExistingOrWrongCapabilityClassTypesThrows(string $wrongImplementationClassTypes): void
    {
        $this->testQueryingWithInvalidCapabilityClassNameThrows($wrongImplementationClassTypes, 'RuntimeException');
    }
}
