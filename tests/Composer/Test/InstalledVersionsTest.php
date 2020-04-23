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

use Composer\Test\TestCase;
use Composer\InstalledVersions;
use Composer\Semver\VersionParser;

class InstalledVersionsTest extends TestCase
{
    public function setUp()
    {
        InstalledVersions::reload(require __DIR__.'/Repository/Fixtures/installed.php');
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
        );
        $this->assertSame($names, InstalledVersions::getInstalledPackages());
    }

    /**
     * @dataProvider isInstalledProvider
     */
    public function testIsInstalled($expected, $name, $constraint = null)
    {
        $this->assertSame($expected, InstalledVersions::isInstalled($name));
    }

    public static function isInstalledProvider()
    {
        return array(
            array(true,  'foo/impl'),
            array(true,  'foo/replaced'),
            array(true,  'c/c'),
            array(true,  '__root__'),
            array(true,  'b/replacer'),
            array(false, 'not/there'),
            array(false, 'not/there', '^1.0'),
        );
    }

    /**
     * @dataProvider satisfiesProvider
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
            'aliases' => array(
                '1.10.x-dev',
            ),
            'reference' => 'sourceref-by-default',
            'name' => '__root__',
        ), InstalledVersions::getRootPackage());
    }

    public function testGetRawData()
    {
        $this->assertSame(require __DIR__.'/Repository/Fixtures/installed.php', InstalledVersions::getRawData());
    }

    /**
     * @dataProvider getReferenceProvider
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
}
