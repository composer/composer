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

namespace Composer\Test;

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use Composer\Semver\VersionParser;

class InstalledVersionsTest extends TestCase
{
    /** @var array<ClassLoader> */
    private static $previousRegisteredLoaders;

    /**
     * @var string
     */
    private $root;

    public static function setUpBeforeClass(): void
    {
        // disable multiple-ClassLoader-based checks of InstalledVersions by making it seem like no
        // class loaders are registered
        $prop = new \ReflectionProperty('Composer\Autoload\ClassLoader', 'registeredLoaders');
        $prop->setAccessible(true);
        self::$previousRegisteredLoaders = $prop->getValue();
        $prop->setValue(null, []);
    }

    public static function tearDownAfterClass(): void
    {
        $prop = new \ReflectionProperty('Composer\Autoload\ClassLoader', 'registeredLoaders');
        $prop->setAccessible(true);
        $prop->setValue(null, self::$previousRegisteredLoaders);
        InstalledVersions::reload(null); // @phpstan-ignore-line
    }

    public function setUp(): void
    {
        $this->root = self::getUniqueTmpDirectory();

        $dir = $this->root;
        InstalledVersions::reload(require __DIR__.'/Repository/Fixtures/installed.php');
    }

    public function testGetInstalledPackages(): void
    {
        $names = [
            '__root__',
            'a/provider',
            'a/provider2',
            'b/replacer',
            'c/c',
            'foo/impl',
            'foo/impl2',
            'foo/replaced',
            'meta/package',
        ];
        $this->assertSame($names, InstalledVersions::getInstalledPackages());
    }

    /**
     * @dataProvider isInstalledProvider
     */
    public function testIsInstalled(bool $expected, string $name, bool $includeDevRequirements = true): void
    {
        $this->assertSame($expected, InstalledVersions::isInstalled($name, $includeDevRequirements));
    }

    public static function isInstalledProvider(): array
    {
        return [
            [true,  'foo/impl'],
            [true,  'foo/replaced'],
            [true,  'c/c'],
            [false, 'c/c', false],
            [true,  '__root__'],
            [true,  'b/replacer'],
            [false, 'not/there'],
            [true,  'meta/package'],
        ];
    }

    /**
     * @dataProvider satisfiesProvider
     */
    public function testSatisfies(bool $expected, string $name, string $constraint): void
    {
        $this->assertSame($expected, InstalledVersions::satisfies(new VersionParser, $name, $constraint));
    }

    public static function satisfiesProvider(): array
    {
        return [
            [true,  'foo/impl', '1.5'],
            [true,  'foo/impl', '1.2'],
            [true,  'foo/impl', '^1.0'],
            [true,  'foo/impl', '^3 || ^2'],
            [false, 'foo/impl', '^3'],

            [true,  'foo/replaced', '3.5'],
            [true,  'foo/replaced', '^3.2'],
            [false,  'foo/replaced', '4.0'],

            [true,  'c/c', '3.0.0'],
            [true,  'c/c', '^3'],
            [false, 'c/c', '^3.1'],

            [true,  '__root__', 'dev-master'],
            [true,  '__root__', '^1.10'],
            [false, '__root__', '^2'],

            [true,  'b/replacer', '^2.1'],
            [false, 'b/replacer', '^2.3'],

            [true,  'a/provider2', '^1.2'],
            [true,  'a/provider2', '^1.4'],
            [false, 'a/provider2', '^1.5'],
        ];
    }

    /**
     * @dataProvider getVersionRangesProvider
     */
    public function testGetVersionRanges(string $expected, string $name): void
    {
        $this->assertSame($expected, InstalledVersions::getVersionRanges($name));
    }

    public static function getVersionRangesProvider(): array
    {
        return [
            ['dev-master || 1.10.x-dev',   '__root__'],
            ['^1.1 || 1.2 || 1.4 || 2.0',  'foo/impl'],
            ['2.2 || 2.0',                 'foo/impl2'],
            ['^3.0',                       'foo/replaced'],
            ['1.1',                        'a/provider'],
            ['1.2 || 1.4',                 'a/provider2'],
            ['2.2',                        'b/replacer'],
            ['3.0',                        'c/c'],
        ];
    }

    /**
     * @dataProvider getVersionProvider
     */
    public function testGetVersion(?string $expected, string $name): void
    {
        $this->assertSame($expected, InstalledVersions::getVersion($name));
    }

    public static function getVersionProvider(): array
    {
        return [
            ['dev-master',  '__root__'],
            [null, 'foo/impl'],
            [null, 'foo/impl2'],
            [null, 'foo/replaced'],
            ['1.1.0.0', 'a/provider'],
            ['1.2.0.0', 'a/provider2'],
            ['2.2.0.0', 'b/replacer'],
            ['3.0.0.0', 'c/c'],
        ];
    }

    /**
     * @dataProvider getPrettyVersionProvider
     */
    public function testGetPrettyVersion(?string $expected, string $name): void
    {
        $this->assertSame($expected, InstalledVersions::getPrettyVersion($name));
    }

    public static function getPrettyVersionProvider(): array
    {
        return [
            ['dev-master',  '__root__'],
            [null, 'foo/impl'],
            [null, 'foo/impl2'],
            [null, 'foo/replaced'],
            ['1.1', 'a/provider'],
            ['1.2', 'a/provider2'],
            ['2.2', 'b/replacer'],
            ['3.0', 'c/c'],
        ];
    }

    public function testGetVersionOutOfBounds(): void
    {
        self::expectException('OutOfBoundsException');
        InstalledVersions::getVersion('not/installed');
    }

    public function testGetRootPackage(): void
    {
        $this->assertSame([
            'name' => '__root__',
            'pretty_version' => 'dev-master',
            'version' => 'dev-master',
            'reference' => 'sourceref-by-default',
            'type' => 'library',
            'install_path' => $this->root . '/./',
            'aliases' => [
                '1.10.x-dev',
            ],
            'dev' => true,
        ], InstalledVersions::getRootPackage());
    }

    /**
     * @group legacy
     */
    public function testGetRawData(): void
    {
        $dir = $this->root;
        $this->assertSame(require __DIR__.'/Repository/Fixtures/installed.php', InstalledVersions::getRawData());
    }

    /**
     * @dataProvider getReferenceProvider
     */
    public function testGetReference(?string $expected, string $name): void
    {
        $this->assertSame($expected, InstalledVersions::getReference($name));
    }

    public static function getReferenceProvider(): array
    {
        return [
            ['sourceref-by-default',  '__root__'],
            [null, 'foo/impl'],
            [null, 'foo/impl2'],
            [null, 'foo/replaced'],
            ['distref-as-no-source', 'a/provider'],
            ['distref-as-installed-from-dist', 'a/provider2'],
            [null, 'b/replacer'],
            [null, 'c/c'],
        ];
    }

    public function testGetInstalledPackagesByType(): void
    {
        $names = [
            '__root__',
            'a/provider',
            'a/provider2',
            'b/replacer',
            'c/c',
        ];

        $this->assertSame($names, \Composer\InstalledVersions::getInstalledPackagesByType('library'));
    }

    public function testGetInstallPath(): void
    {
        $this->assertSame(realpath($this->root), realpath(\Composer\InstalledVersions::getInstallPath('__root__')));
        $this->assertSame('/foo/bar/vendor/c/c', \Composer\InstalledVersions::getInstallPath('c/c'));
        $this->assertNull(\Composer\InstalledVersions::getInstallPath('foo/impl'));
    }
}
