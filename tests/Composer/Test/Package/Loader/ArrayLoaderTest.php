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
    public function setUp()
    {
        $this->loader = new ArrayLoader();
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
    }
}
