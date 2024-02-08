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

    public static function setUpBeforeClass()
    {
        // disable multiple-ClassLoader-based checks of InstalledVersions by making it seem like no
        // class loaders are registered
        $prop = new \ReflectionProperty('Composer\Autoload\ClassLoader', 'registeredLoaders');
        $prop->setAccessible(true);
        self::$previousRegisteredLoaders = $prop->getValue();
        $prop->setValue(array());
    }

    public static function tearDownAfterClass()
    {
        $prop = new \ReflectionProperty('Composer\Autoload\ClassLoader', 'registeredLoaders');
        $prop->setAccessible(true);
        $prop->setValue(self::$previousRegisteredLoaders);
        InstalledVersions::reload(null); // @phpstan-ignore-line
    }

    public function setUp()
    {
        $this->root = $this->getUniqueTmpDirectory();

        $dir = $this->root;
        InstalledVersions::reload(require __DIR__.'/Repository/Fixtures/installed_relative.php');
    }

    public function testGetInstalledPackages()
    {
        $names = array(
            '__root__',
            'a/provider',
            'a/provider2',
            'b/replacer',
            'c/c',
            'foo/impl',
            'foo/impl2',
            'foo/replaced',
            'meta/package',
        );
        $this->assertSame($names, InstalledVersions::getInstalledPackages());
    }

    /**
     * @dataProvider isInstalledProvider
     * @param bool $expected
     * @param string $name
     * @param bool $includeDevRequirements
     */
    public function testIsInstalled($expected, $name, $includeDevRequirements = true)
    {
        $this->assertSame($expected, InstalledVersions::isInstalled($name, $includeDevRequirements));
    }

    public static function isInstalledProvider()
    {
        return array(
            array(true,  'foo/impl'),
            array(true,  'foo/replaced'),
            array(true,  'c/c'),
            array(false, 'c/c', false),
            array(true,  '__root__'),
            array(true,  'b/replacer'),
            array(false, 'not/there'),
            array(true,  'meta/package'),
        );
    }

    /**
     * @dataProvider satisfiesProvider
     * @param bool $expected
     * @param string $name
     * @param string $constraint
     */
    public function testSatisfies($expected, $name, $constraint)
    {
        $this->assertSame($expected, InstalledVersions::satisfies(new VersionParser, $name, $constraint));
    }

    public static function satisfiesProvider()
    {
        return array(
            array(true,  'foo/impl', '1.5'),
            array(true,  'foo/impl', '1.2'),
            array(true,  'foo/impl', '^1.0'),
            array(true,  'foo/impl', '^3 || ^2'),
            array(false, 'foo/impl', '^3'),

            array(true,  'foo/replaced', '3.5'),
            array(true,  'foo/replaced', '^3.2'),
            array(false,  'foo/replaced', '4.0'),

            array(true,  'c/c', '3.0.0'),
            array(true,  'c/c', '^3'),
            array(false, 'c/c', '^3.1'),

            array(true,  '__root__', 'dev-master'),
            array(true,  '__root__', '^1.10'),
            array(false, '__root__', '^2'),

            array(true,  'b/replacer', '^2.1'),
            array(false, 'b/replacer', '^2.3'),

            array(true,  'a/provider2', '^1.2'),
            array(true,  'a/provider2', '^1.4'),
            array(false, 'a/provider2', '^1.5'),
        );
    }

    /**
     * @dataProvider getVersionRangesProvider
     * @param string $expected
     * @param string $name
     */
    public function testGetVersionRanges($expected, $name)
    {
        $this->assertSame($expected, InstalledVersions::getVersionRanges($name));
    }

    public static function getVersionRangesProvider()
    {
        return array(
            array('dev-master || 1.10.x-dev',   '__root__'),
            array('^1.1 || 1.2 || 1.4 || 2.0',  'foo/impl'),
            array('2.2 || 2.0',                 'foo/impl2'),
            array('^3.0',                       'foo/replaced'),
            array('1.1',                        'a/provider'),
            array('1.2 || 1.4',                 'a/provider2'),
            array('2.2',                        'b/replacer'),
            array('3.0',                        'c/c'),
        );
    }

    /**
     * @dataProvider getVersionProvider
     * @param ?string $expected
     * @param string $name
     */
    public function testGetVersion($expected, $name)
    {
        $this->assertSame($expected, InstalledVersions::getVersion($name));
    }

    public static function getVersionProvider()
    {
        return array(
            array('dev-master',  '__root__'),
            array(null, 'foo/impl'),
            array(null, 'foo/impl2'),
            array(null, 'foo/replaced'),
            array('1.1.0.0', 'a/provider'),
            array('1.2.0.0', 'a/provider2'),
            array('2.2.0.0', 'b/replacer'),
            array('3.0.0.0', 'c/c'),
        );
    }

    /**
     * @dataProvider getPrettyVersionProvider
     * @param ?string $expected
     * @param string $name
     */
    public function testGetPrettyVersion($expected, $name)
    {
        $this->assertSame($expected, InstalledVersions::getPrettyVersion($name));
    }

    public static function getPrettyVersionProvider()
    {
        return array(
            array('dev-master',  '__root__'),
            array(null, 'foo/impl'),
            array(null, 'foo/impl2'),
            array(null, 'foo/replaced'),
            array('1.1', 'a/provider'),
            array('1.2', 'a/provider2'),
            array('2.2', 'b/replacer'),
            array('3.0', 'c/c'),
        );
    }

    public function testGetVersionOutOfBounds()
    {
        $this->setExpectedException('OutOfBoundsException');
        InstalledVersions::getVersion('not/installed');
    }

    public function testGetRootPackage()
    {
        $this->assertSame(array(
            'pretty_version' => 'dev-master',
            'version' => 'dev-master',
            'type' => 'library',
            'install_path' => $this->root . '/./',
            'aliases' => array(
                '1.10.x-dev',
            ),
            'reference' => 'sourceref-by-default',
            'name' => '__root__',
            'dev' => true,
        ), InstalledVersions::getRootPackage());
    }

    /**
     * @group legacy
     */
    public function testGetRawData()
    {
        $dir = $this->root;
        $this->assertSame(require __DIR__.'/Repository/Fixtures/installed_relative.php', InstalledVersions::getRawData());
    }

    /**
     * @dataProvider getReferenceProvider
     * @param ?string $expected
     * @param string $name
     */
    public function testGetReference($expected, $name)
    {
        $this->assertSame($expected, InstalledVersions::getReference($name));
    }

    public static function getReferenceProvider()
    {
        return array(
            array('sourceref-by-default',  '__root__'),
            array(null, 'foo/impl'),
            array(null, 'foo/impl2'),
            array(null, 'foo/replaced'),
            array('distref-as-no-source', 'a/provider'),
            array('distref-as-installed-from-dist', 'a/provider2'),
            array(null, 'b/replacer'),
            array(null, 'c/c'),
        );
    }

    public function testGetInstalledPackagesByType()
    {
        $names = array(
            '__root__',
            'a/provider',
            'a/provider2',
            'b/replacer',
            'c/c',
        );

        $this->assertSame($names, \Composer\InstalledVersions::getInstalledPackagesByType('library'));
    }

    public function testGetInstallPath()
    {
        $this->assertSame(realpath($this->root), realpath(\Composer\InstalledVersions::getInstallPath('__root__')));
        $this->assertSame('/foo/bar/vendor/c/c', \Composer\InstalledVersions::getInstallPath('c/c'));
        $this->assertNull(\Composer\InstalledVersions::getInstallPath('foo/impl'));
    }
}
