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

use Composer\Autoload\AutoloadGenerator;
use Composer\Composer;
use Composer\Config;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\BufferIO;
use Composer\Json\JsonFile;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\Link;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Loader\JsonLoader;
use Composer\Package\Locker;
use Composer\Package\RootPackage;
use Composer\Plugin\PluginManager;
use Composer\Repository\InstalledFilesystemRepository;
use Composer\Semver\VersionParser;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;
use Composer\Util\Platform;

class PluginManagerTest extends TestCase
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
     * @var BufferIO
     */
    protected $io;

    /**
     * @var string
     */
    protected $directory;

    protected function tearDown(): void
    {
        parent::tearDown();
        $filesystem = new Filesystem();
        $filesystem->removeDirectory($this->directory);
    }

    /**
     * The plugin depends on the root package, which itself requires a package that a
     * custom installer (provided by the plugin) installs outside of vendor/. When the
     * plugin is registered, that installer is not active yet, so the installation
     * manager can only derive a vendor/ path for the dependency. The runtime autoloader
     * must use the install path recorded in installed.json instead, otherwise it scans
     * for classes in a vendor/ directory which does not exist.
     */
    public function testRegisterPackageAutoloadsDependenciesFromRecordedInstallPaths(): void
    {
        $this->directory = self::getUniqueTmpDirectory();
        $dir = $this->directory;
        $fixtures = __DIR__.'/Fixtures/custom-installer';

        // a package installed by a custom installer to plugins/thing instead of vendor/acme/thing
        $this->copyDirectory($fixtures.'/installer-plugin', $dir.'/vendor/acme/installer-plugin');
        $this->copyDirectory($fixtures.'/thing', $dir.'/plugins/thing');

        $loader = new JsonLoader(new ArrayLoader());
        $pluginPackage = $loader->load($fixtures.'/installer-plugin/composer.json');
        $thingPackage = $loader->load($fixtures.'/thing/composer.json');

        $dumper = new ArrayDumper();
        $pluginArray = $dumper->dump($pluginPackage);
        $pluginArray['install-path'] = '../acme/installer-plugin';
        $thingArray = $dumper->dump($thingPackage);
        $thingArray['install-path'] = '../../plugins/thing';

        $installedJson = new JsonFile($dir.'/vendor/composer/installed.json');
        $installedJson->write([
            'packages' => [$pluginArray, $thingArray],
            'dev' => true,
            'dev-package-names' => [],
        ]);

        $localRepo = new InstalledFilesystemRepository($installedJson);

        $rm = $this->getMockBuilder('Composer\Repository\RepositoryManager')
            ->disableOriginalConstructor()
            ->getMock();
        $rm->expects($this->any())
            ->method('getLocalRepository')
            ->will($this->returnValue($localRepo));

        // models the default LibraryInstaller fallback which is the only installer able to
        // answer for custom types before the plugin registering them got activated: it
        // always derives a path inside vendor/, which is wrong for acme/thing
        $im = $this->getMockBuilder('Composer\Installer\InstallationManager')->disableOriginalConstructor()->getMock();
        $im->expects($this->any())
            ->method('getInstallPath')
            ->will($this->returnCallback(static function ($package) use ($dir): string {
                return $dir.'/vendor/'.$package->getPrettyName();
            }));

        $this->io = new BufferIO();

        $rootPackage = new RootPackage('acme/root', '1.0.0.0', '1.0.0');
        $versionParser = new VersionParser();
        $rootPackage->setRequires([
            'acme/installer-plugin' => new Link('acme/root', 'acme/installer-plugin', $versionParser->parseConstraints('*')),
            'acme/thing' => new Link('acme/root', 'acme/thing', $versionParser->parseConstraints('*')),
        ]);

        $this->composer = new Composer();
        $config = new Config(false);
        $this->composer->setConfig($config);
        $this->composer->setRepositoryManager($rm);
        $this->composer->setInstallationManager($im);
        $this->composer->setPackage($rootPackage);
        $this->composer->setLocker(new Locker($this->io, new JsonFile(Platform::getDevNull()), $im, '{}'));

        $dispatcher = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')->disableOriginalConstructor()->getMock();
        $this->composer->setAutoloadGenerator(new AutoloadGenerator($dispatcher, $this->io));
        $this->composer->setEventDispatcher(new EventDispatcher($this->composer, $this->io));

        $config->merge([
            'config' => [
                'vendor-dir' => $dir.'/vendor',
                'home' => $dir,
                'allow-plugins' => true,
            ],
        ]);

        $this->pm = new PluginManager($this->io, $this->composer);
        $this->composer->setPluginManager($this->pm);

        $registeredPackage = $localRepo->findPackage('acme/installer-plugin', '*');
        self::assertNotNull($registeredPackage);
        $this->pm->registerPackage($registeredPackage, true);

        $plugins = $this->pm->getPlugins();
        self::assertCount(1, $plugins);
        self::assertSame('activate custom-installer-plugin'.PHP_EOL, $this->io->getOutput());

        // the dependency's classes must be autoloadable from plugins/thing, where the
        // custom installer put them, and no scan of the non-existent vendor/ path may occur
        self::assertTrue(class_exists('AcmeThingMain', true));
        self::assertTrue(class_exists('AcmeThingHelper', true));
        self::assertTrue(class_exists('Acme\Thing\Thing', true));
    }

    private function copyDirectory(string $source, string $target): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $targetPath = $target.'/'.$iterator->getSubPathname();
            if ($item->isDir()) {
                mkdir($targetPath, 0777, true);
            } else {
                if (!is_dir(dirname($targetPath))) {
                    mkdir(dirname($targetPath), 0777, true);
                }
                copy($item->getPathname(), $targetPath);
            }
        }
    }
}
