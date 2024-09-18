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
use Composer\Json\JsonFile;
use Composer\Package\CompleteAliasPackage;
use Composer\Package\CompletePackage;
use Composer\Package\Loader\JsonLoader;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Locker;
use Composer\Package\RootPackage;
use Composer\Plugin\PluginManager;
use Composer\IO\BufferIO;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Autoload\AutoloadGenerator;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Composer\Util\Platform;

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
        $this->packages = [];
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
            ->will($this->returnCallback(static function ($package): string {
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
        $this->composer->setLocker(new Locker($this->io, new JsonFile(Platform::getDevNull()), $im, '{}'));

        $config->merge([
            'config' => [
                'vendor-dir' => $this->directory.'/Fixtures/',
                'home' => $this->directory.'/Fixtures',
                'bin-dir' => $this->directory.'/Fixtures/bin',
                'allow-plugins' => true,
            ],
        ]);

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
            ->will($this->returnValue([]));
        $installer = new PluginInstaller($this->io, $this->composer);
        $this->pm->loadInstalledPlugins();

        $installer->install($this->repository, $this->packages[0]);

        $plugins = $this->pm->getPlugins();
        self::assertEquals('installer-v1', $plugins[0]->version);  // @phpstan-ignore property.notFound
        self::assertEquals(
            'activate v1'.PHP_EOL,
            $this->io->getOutput()
        );
    }

    public function testInstallPluginWithRootPackageHavingFilesAutoload(): void
    {
        $this->repository
            ->expects($this->any())
            ->method('getPackages')
            ->will($this->returnValue([]));
        $installer = new PluginInstaller($this->io, $this->composer);
        $this->pm->loadInstalledPlugins();

        $this->autoloadGenerator->setDevMode(true);
        $this->composer->getPackage()->setAutoload(['files' => [__DIR__ . '/Fixtures/files_autoload_which_should_not_run.php']]);
        $this->composer->getPackage()->setDevAutoload(['files' => [__DIR__ . '/Fixtures/files_autoload_which_should_not_run.php']]);
        $installer->install($this->repository, $this->packages[0]);

        $plugins = $this->pm->getPlugins();
        self::assertEquals(
            'activate v1'.PHP_EOL,
            $this->io->getOutput()
        );
        self::assertEquals('installer-v1', $plugins[0]->version);  // @phpstan-ignore property.notFound
    }

    public function testInstallMultiplePlugins(): void
    {
        $this->repository
            ->expects($this->any())
            ->method('getPackages')
            ->will($this->returnValue([$this->packages[3]]));
        $installer = new PluginInstaller($this->io, $this->composer);
        $this->pm->loadInstalledPlugins();

        $installer->install($this->repository, $this->packages[3]);

        $plugins = $this->pm->getPlugins();
        self::assertEquals('plugin1', $plugins[0]->name); // @phpstan-ignore property.notFound
        self::assertEquals('installer-v4', $plugins[0]->version); // @phpstan-ignore property.notFound
        self::assertEquals('plugin2', $plugins[1]->name); // @phpstan-ignore property.notFound
        self::assertEquals('installer-v4', $plugins[1]->version); // @phpstan-ignore property.notFound
        self::assertEquals('activate v4-plugin1'.PHP_EOL.'activate v4-plugin2'.PHP_EOL, $this->io->getOutput());
    }

    public function testUpgradeWithNewClassName(): void
    {
        $this->repository
            ->expects($this->any())
            ->method('getPackages')
            ->will($this->returnValue([$this->packages[0]]));
        $this->repository
            ->expects($this->exactly(2))
            ->method('hasPackage')
            ->will($this->onConsecutiveCalls(true, false));
        $installer = new PluginInstaller($this->io, $this->composer);
        $this->pm->loadInstalledPlugins();

        $installer->update($this->repository, $this->packages[0], $this->packages[1]);

        $plugins = $this->pm->getPlugins();
        self::assertCount(1, $plugins);
        self::assertEquals('installer-v2', $plugins[1]->version); // @phpstan-ignore property.notFound
        self::assertEquals('activate v1'.PHP_EOL.'deactivate v1'.PHP_EOL.'activate v2'.PHP_EOL, $this->io->getOutput());
    }

    public function testUninstall(): void
    {
        $this->repository
            ->expects($this->any())
            ->method('getPackages')
            ->will($this->returnValue([$this->packages[0]]));
        $this->repository
            ->expects($this->exactly(1))
            ->method('hasPackage')
            ->will($this->onConsecutiveCalls(true, false));
        $installer = new PluginInstaller($this->io, $this->composer);
        $this->pm->loadInstalledPlugins();

        $installer->uninstall($this->repository, $this->packages[0]);

        $plugins = $this->pm->getPlugins();
        self::assertCount(0, $plugins);
        self::assertEquals('activate v1'.PHP_EOL.'deactivate v1'.PHP_EOL.'uninstall v1'.PHP_EOL, $this->io->getOutput());
    }

    public function testUpgradeWithSameClassName(): void
    {
        $this->repository
            ->expects($this->any())
            ->method('getPackages')
            ->will($this->returnValue([$this->packages[1]]));
        $this->repository
            ->expects($this->exactly(2))
            ->method('hasPackage')
            ->will($this->onConsecutiveCalls(true, false));
        $installer = new PluginInstaller($this->io, $this->composer);
        $this->pm->loadInstalledPlugins();

        $installer->update($this->repository, $this->packages[1], $this->packages[2]);

        $plugins = $this->pm->getPlugins();
        self::assertEquals('installer-v3', $plugins[1]->version); // @phpstan-ignore property.notFound
        self::assertEquals('activate v2'.PHP_EOL.'deactivate v2'.PHP_EOL.'activate v3'.PHP_EOL, $this->io->getOutput());
    }

    public function testRegisterPluginOnlyOneTime(): void
    {
        $this->repository
            ->expects($this->any())
            ->method('getPackages')
            ->will($this->returnValue([]));
        $installer = new PluginInstaller($this->io, $this->composer);
        $this->pm->loadInstalledPlugins();

        $installer->install($this->repository, $this->packages[0]);
        $installer->install($this->repository, clone $this->packages[0]);

        $plugins = $this->pm->getPlugins();
        self::assertCount(1, $plugins);
        self::assertEquals('installer-v1', $plugins[0]->version); // @phpstan-ignore property.notFound
        self::assertEquals('activate v1'.PHP_EOL, $this->io->getOutput());
    }

    /**
     * @param array<CompletePackage|CompleteAliasPackage> $plugins
     */
    private function setPluginApiVersionWithPlugins(string $newPluginApiVersion, array $plugins = []): void
    {
        // reset the plugin manager's installed plugins
        $this->pm = $this->getMockBuilder('Composer\Plugin\PluginManager')
                         ->onlyMethods(['getPluginApiVersion'])
                         ->setConstructorArgs([$this->io, $this->composer])
                         ->getMock();

        // mock the Plugin API version
        $this->pm->expects($this->any())
                 ->method('getPluginApiVersion')
                 ->will($this->returnValue($newPluginApiVersion));

        $plugApiInternalPackage = self::getPackage(
            'composer-plugin-api',
            $newPluginApiVersion,
            'Composer\Package\CompletePackage'
        );

        // Add the plugins to the repo along with the internal Plugin package on which they all rely.
        $this->repository
            ->expects($this->any())
            ->method('getPackages')
            ->will($this->returnCallback(static function () use ($plugApiInternalPackage, $plugins): array {
                return array_merge([$plugApiInternalPackage], $plugins);
            }));

        $this->pm->loadInstalledPlugins();
    }

    public function testStarPluginVersionWorksWithAnyAPIVersion(): void
    {
        $starVersionPlugin = [$this->packages[4]];

        $this->setPluginApiVersionWithPlugins('1.0.0', $starVersionPlugin);
        self::assertCount(1, $this->pm->getPlugins());

        $this->setPluginApiVersionWithPlugins('1.9.9', $starVersionPlugin);
        self::assertCount(1, $this->pm->getPlugins());

        $this->setPluginApiVersionWithPlugins('2.0.0-dev', $starVersionPlugin);
        self::assertCount(1, $this->pm->getPlugins());

        $this->setPluginApiVersionWithPlugins('100.0.0-stable', $starVersionPlugin);
        self::assertCount(1, $this->pm->getPlugins());
    }

    public function testPluginConstraintWorksOnlyWithCertainAPIVersion(): void
    {
        $pluginWithApiConstraint = [$this->packages[5]];

        $this->setPluginApiVersionWithPlugins('1.0.0', $pluginWithApiConstraint);
        self::assertCount(0, $this->pm->getPlugins());

        $this->setPluginApiVersionWithPlugins('1.1.9', $pluginWithApiConstraint);
        self::assertCount(0, $this->pm->getPlugins());

        $this->setPluginApiVersionWithPlugins('1.2.0', $pluginWithApiConstraint);
        self::assertCount(1, $this->pm->getPlugins());

        $this->setPluginApiVersionWithPlugins('1.9.9', $pluginWithApiConstraint);
        self::assertCount(1, $this->pm->getPlugins());
    }

    public function testPluginRangeConstraintsWorkOnlyWithCertainAPIVersion(): void
    {
        $pluginWithApiConstraint = [$this->packages[6]];

        $this->setPluginApiVersionWithPlugins('1.0.0', $pluginWithApiConstraint);
        self::assertCount(0, $this->pm->getPlugins());

        $this->setPluginApiVersionWithPlugins('3.0.0', $pluginWithApiConstraint);
        self::assertCount(1, $this->pm->getPlugins());

        $this->setPluginApiVersionWithPlugins('5.5.0', $pluginWithApiConstraint);
        self::assertCount(0, $this->pm->getPlugins());
    }

    public function testCommandProviderCapability(): void
    {
        $this->repository
            ->expects($this->any())
            ->method('getPackages')
            ->will($this->returnValue([$this->packages[7]]));
        $installer = new PluginInstaller($this->io, $this->composer);
        $this->pm->loadInstalledPlugins();

        /** @var \Composer\Plugin\Capability\CommandProvider[] $caps */
        $caps = $this->pm->getPluginCapabilities('Composer\Plugin\Capability\CommandProvider', ['composer' => $this->composer, 'io' => $this->io]);
        self::assertCount(1, $caps);
        self::assertInstanceOf('Composer\Plugin\Capability\CommandProvider', $caps[0]);

        $commands = $caps[0]->getCommands();
        self::assertCount(1, $commands);
        self::assertInstanceOf('Composer\Command\BaseCommand', $commands[0]);
    }

    public function testIncapablePluginIsCorrectlyDetected(): void
    {
        $plugin = $this->getMockBuilder('Composer\Plugin\PluginInterface')
                       ->getMock();
        self::assertNull($this->pm->getPluginCapability($plugin, 'Fake\Ability'));
    }

    public function testCapabilityImplementsComposerPluginApiClassAndIsConstructedWithArgs(): void
    {
        $capabilityApi = 'Composer\Plugin\Capability\Capability';
        $capabilityImplementation = 'Composer\Test\Plugin\Mock\Capability';

        $plugin = $this->getMockBuilder('Composer\Test\Plugin\Mock\CapablePluginInterface')
                       ->getMock();

        $plugin->expects($this->once())
               ->method('getCapabilities')
               ->will($this->returnCallback(static function () use ($capabilityImplementation, $capabilityApi): array {
                   return [$capabilityApi => $capabilityImplementation];
               }));

        /** @var \Composer\Test\Plugin\Mock\Capability $capability */
        $capability = $this->pm->getPluginCapability($plugin, $capabilityApi, ['a' => 1, 'b' => 2]);

        self::assertInstanceOf($capabilityApi, $capability);
        self::assertInstanceOf($capabilityImplementation, $capability);
        self::assertSame(['a' => 1, 'b' => 2, 'plugin' => $plugin], $capability->args);
    }

    /** @return mixed[] */
    public static function invalidImplementationClassNames(): array
    {
        return [
            [null],
            [""],
            [0],
            [1000],
            ["   "],
            [[1]],
            [[]],
            [new \stdClass()],
        ];
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
               ->will($this->returnCallback(static function () use ($invalidImplementationClassNames, $capabilityApi): array {
                   return [$capabilityApi => $invalidImplementationClassNames];
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
               ->will($this->returnCallback(static function (): array {
                   return [];
               }));

        self::assertNull($this->pm->getPluginCapability($plugin, $capabilityApi));
    }

    /** @return mixed[] */
    public static function nonExistingOrInvalidImplementationClassTypes(): array
    {
        return [
            ['\stdClass'],
            ['NonExistentClassLikeMiddleClass'],
        ];
    }

    /**
     * @dataProvider nonExistingOrInvalidImplementationClassTypes
     */
    public function testQueryingWithNonExistingOrWrongCapabilityClassTypesThrows(string $wrongImplementationClassTypes): void
    {
        $this->testQueryingWithInvalidCapabilityClassNameThrows($wrongImplementationClassTypes, 'RuntimeException');
    }
}
