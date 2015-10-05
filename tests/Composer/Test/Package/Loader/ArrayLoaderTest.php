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

namespace Composer\Test\Package\Loader;

use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Dumper\ArrayDumper;

class ArrayLoaderTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var ArrayLoader
     */
    private $loader;

    public function setUp()
    {
        $this->loader = new ArrayLoader(null, true);
    }

    public function testSelfVersion()
    {
        $config = array(
            'name' => 'A',
            'version' => '1.2.3.4',
            'replace' => array(
                'foo' => 'self.version',
            ),
        );

        $package = $this->loader->load($config);
        $replaces = $package->getReplaces();
        $this->assertEquals('== 1.2.3.4', (string) $replaces['foo']->getConstraint());
    }

    public function testTypeDefault()
    {
        $config = array(
            'name' => 'A',
            'version' => '1.0',
        );

        $package = $this->loader->load($config);
        $this->assertEquals('library', $package->getType());

        $config = array(
            'name' => 'A',
            'version' => '1.0',
            'type' => 'foo',
        );

        $package = $this->loader->load($config);
        $this->assertEquals('foo', $package->getType());
    }

    public function testNormalizedVersionOptimization()
    {
        $config = array(
            'name' => 'A',
            'version' => '1.2.3',
        );

        $package = $this->loader->load($config);
        $this->assertEquals('1.2.3.0', $package->getVersion());

        $config = array(
            'name' => 'A',
            'version' => '1.2.3',
            'version_normalized' => '1.2.3.4',
        );

        $package = $this->loader->load($config);
        $this->assertEquals('1.2.3.4', $package->getVersion());
    }

    public function testParseDump()
    {
        $config = array(
            'name' => 'A/B',
            'version' => '1.2.3',
            'version_normalized' => '1.2.3.0',
            'description' => 'Foo bar',
            'type' => 'library',
            'keywords' => array('a', 'b', 'c'),
            'homepage' => 'http://example.com',
            'license' => array('MIT', 'GPLv3'),
            'authors' => array(
                array('name' => 'Bob', 'email' => 'bob@example.org', 'homepage' => 'example.org', 'role' => 'Developer'),
            ),
            'require' => array(
                'foo/bar' => '1.0',
            ),
            'require-dev' => array(
                'foo/baz' => '1.0',
            ),
            'replace' => array(
                'foo/qux' => '1.0',
            ),
            'conflict' => array(
                'foo/quux' => '1.0',
            ),
            'provide' => array(
                'foo/quuux' => '1.0',
            ),
            'autoload' => array(
                'psr-0' => array('Ns\Prefix' => 'path'),
                'classmap' => array('path', 'path2'),
            ),
            'include-path' => array('path3', 'path4'),
            'target-dir' => 'some/prefix',
            'extra' => array('random' => array('things' => 'of', 'any' => 'shape')),
            'bin' => array('bin1', 'bin/foo'),
            'archive' => array(
                'exclude' => array('/foo/bar', 'baz', '!/foo/bar/baz'),
            ),
            'transport-options' => array('ssl' => array('local_cert' => '/opt/certs/test.pem')),
            'abandoned' => 'foo/bar',
        );

        $package = $this->loader->load($config);
        $dumper = new ArrayDumper;
        $this->assertEquals($config, $dumper->dump($package));
    }

    public function testPackageWithBranchAlias()
    {
        $config = array(
            'name' => 'A',
            'version' => 'dev-master',
            'extra' => array('branch-alias' => array('dev-master' => '1.0.x-dev')),
        );

        $package = $this->loader->load($config);

        $this->assertInstanceOf('Composer\Package\AliasPackage', $package);
        $this->assertEquals('1.0.x-dev', $package->getPrettyVersion());

        $config = array(
            'name' => 'A',
            'version' => 'dev-master',
            'extra' => array('branch-alias' => array('dev-master' => '1.0-dev')),
        );

        $package = $this->loader->load($config);

        $this->assertInstanceOf('Composer\Package\AliasPackage', $package);
        $this->assertEquals('1.0.x-dev', $package->getPrettyVersion());

        $config = array(
            'name' => 'B',
            'version' => '4.x-dev',
            'extra' => array('branch-alias' => array('4.x-dev' => '4.0.x-dev')),
        );

        $package = $this->loader->load($config);

        $this->assertInstanceOf('Composer\Package\AliasPackage', $package);
        $this->assertEquals('4.0.x-dev', $package->getPrettyVersion());

        $config = array(
            'name' => 'B',
            'version' => '4.x-dev',
            'extra' => array('branch-alias' => array('4.x-dev' => '4.0-dev')),
        );

        $package = $this->loader->load($config);

        $this->assertInstanceOf('Composer\Package\AliasPackage', $package);
        $this->assertEquals('4.0.x-dev', $package->getPrettyVersion());

        $config = array(
            'name' => 'C',
            'version' => '4.x-dev',
            'extra' => array('branch-alias' => array('4.x-dev' => '3.4.x-dev')),
        );

        $package = $this->loader->load($config);

        $this->assertInstanceOf('Composer\Package\CompletePackage', $package);
        $this->assertEquals('4.x-dev', $package->getPrettyVersion());
    }

    public function testAbandoned()
    {
        $config = array(
            'name' => 'A',
            'version' => '1.2.3.4',
            'abandoned' => 'foo/bar',
        );

        $package = $this->loader->load($config);
        $this->assertTrue($package->isAbandoned());
        $this->assertEquals('foo/bar', $package->getReplacementPackage());
    }

    public function testNotAbandoned()
    {
        $config = array(
            'name' => 'A',
            'version' => '1.2.3.4',
        );

        $package = $this->loader->load($config);
        $this->assertFalse($package->isAbandoned());
    }

    public function pluginApiVersions()
    {
        return array(
            array('1.0'),
            array('1.0.0'),
            array('1.0.0.0'),
            array('1'),
            array('=1.0.0'),
            array('==1.0'),
            array('~1.0.0'),
            array('*'),
            array('3.0.*'),
            array('@stable'),
            array('1.0.0@stable'),
            array('^5.1'),
            array('>=1.0.0 <2.5'),
            array('x'),
            array('1.0.0-dev'),
        );
    }

    /**
     * @dataProvider pluginApiVersions
     */
    public function testPluginApiVersionAreKeptAsDeclared($apiVersion)
    {
        $links = $this->loader->parseLinks('Plugin', '9.9.9', '', array('composer-plugin-api' => $apiVersion));

        $this->assertArrayHasKey('composer-plugin-api', $links);
        $this->assertSame($apiVersion, $links['composer-plugin-api']->getConstraint()->getPrettyString());
    }

    public function testPluginApiVersionDoesSupportSelfVersion()
    {
        $links = $this->loader->parseLinks('Plugin', '6.6.6', '', array('composer-plugin-api' => 'self.version'));

        $this->assertArrayHasKey('composer-plugin-api', $links);
        $this->assertSame('6.6.6', $links['composer-plugin-api']->getConstraint()->getPrettyString());
    }
}
