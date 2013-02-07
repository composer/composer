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

namespace Composer\Test\Package\Dumper;

use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\Link;
use Composer\Package\LinkConstraint\VersionConstraint;

class ArrayDumperTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var ArrayDumper
     */
    private $dumper;
    /**
     * @var \Composer\Package\CompletePackageInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    private $package;

    public function setUp()
    {
        $this->dumper = new ArrayDumper();
        $this->package = $this->getMock('Composer\Package\CompletePackageInterface');
    }

    public function testRequiredInformation()
    {
        $this
            ->packageExpects('getPrettyName', 'foo')
            ->packageExpects('getPrettyVersion', '1.0')
            ->packageExpects('getVersion', '1.0.0.0');

        $config = $this->dumper->dump($this->package);
        $this->assertEquals(
            array(
                'name' => 'foo',
                'version' => '1.0',
                'version_normalized' => '1.0.0.0'
            ),
            $config
        );
    }

    public function testRootPackage()
    {
        $this->package = $this->getMock('Composer\Package\RootPackageInterface');

        $this
            ->packageExpects('getMinimumStability', 'dev');

        $config = $this->dumper->dump($this->package);
        $this->assertSame('dev', $config['minimum-stability']);
    }

    /**
     * @dataProvider getKeys
     */
    public function testKeys($key, $value, $method = null, $expectedValue = null)
    {
        $this->packageExpects('get'.ucfirst($method ?: $key), $value);

        $config = $this->dumper->dump($this->package);

        $this->assertSame($expectedValue ?: $value, $config[$key]);
    }

    public function getKeys()
    {
        return array(
            array(
                'type',
                'library'
            ),
            array(
                'time',
                new \DateTime('2012-02-01'),
                'ReleaseDate',
                '2012-02-01 00:00:00',
            ),
            array(
                'authors',
                array('Nils Adermann <naderman@naderman.de>', 'Jordi Boggiano <j.boggiano@seld.be>')
            ),
            array(
                'homepage',
                'http://getcomposer.org'
            ),
            array(
                'description',
                'Dependency Manager'
            ),
            array(
                'keywords',
                array('package', 'dependency', 'autoload'),
                null,
                array('autoload', 'dependency', 'package')
            ),
            array(
                'bin',
                array('bin/composer'),
                'binaries'
            ),
            array(
                'license',
                array('MIT')
            ),
            array(
                'autoload',
                array('psr-0' => array('Composer' => 'src/'))
            ),
            array(
                'repositories',
                array('packagist' => false)
            ),
            array(
                'scripts',
                array('post-update-cmd' => 'MyVendor\\MyClass::postUpdate')
            ),
            array(
                'extra',
                array('class' => 'MyVendor\\Installer')
            ),
            array(
                'archive',
                array('/foo/bar', 'baz', '!/foo/bar/baz'),
                'archiveExcludes',
                array(
                    'exclude' => array('/foo/bar', 'baz', '!/foo/bar/baz'),
                ),
            ),
            array(
                'require',
                array(new Link('foo', 'foo/bar', new VersionConstraint('=', '1.0.0.0'), 'requires', '1.0.0')),
                'requires',
                array('foo/bar' => '1.0.0'),
            ),
            array(
                'require-dev',
                array(new Link('foo', 'foo/bar', new VersionConstraint('=', '1.0.0.0'), 'requires (for development)', '1.0.0')),
                'devRequires',
                array('foo/bar' => '1.0.0'),
            ),
            array(
                'suggest',
                array('foo/bar' => 'very useful package'),
                'suggests'
            ),
            array(
                'support',
                array('foo' => 'bar'),
            ),
            array(
                'require',
                array(new Link('foo', 'foo/bar', new VersionConstraint('=', '1.0.0.0'), 'requires', '1.0.0'), new Link('bar', 'bar/baz', new VersionConstraint('=', '1.0.0.0'), 'requires', '1.0.0')),
                'requires',
                array('bar/baz' => '1.0.0', 'foo/bar' => '1.0.0')
            ),
            array(
                'require-dev',
                array(new Link('foo', 'foo/bar', new VersionConstraint('=', '1.0.0.0'), 'requires', '1.0.0'), new Link('bar', 'bar/baz', new VersionConstraint('=', '1.0.0.0'), 'requires', '1.0.0')),
                'devRequires',
                array('bar/baz' => '1.0.0', 'foo/bar' => '1.0.0')
            ),
            array(
                'suggest',
                array('foo/bar' => 'very useful package', 'bar/baz' => 'another useful package'),
                'suggests',
                array('bar/baz' => 'another useful package', 'foo/bar' => 'very useful package')
            ),
            array(
                'provide',
                array(new Link('foo', 'foo/bar', new VersionConstraint('=', '1.0.0.0'), 'requires', '1.0.0'), new Link('bar', 'bar/baz', new VersionConstraint('=', '1.0.0.0'), 'requires', '1.0.0')),
                'provides',
                array('bar/baz' => '1.0.0', 'foo/bar' => '1.0.0')
            ),
            array(
                'replace',
                array(new Link('foo', 'foo/bar', new VersionConstraint('=', '1.0.0.0'), 'requires', '1.0.0'), new Link('bar', 'bar/baz', new VersionConstraint('=', '1.0.0.0'), 'requires', '1.0.0')),
                'replaces',
                array('bar/baz' => '1.0.0', 'foo/bar' => '1.0.0')
            ),
            array(
                'conflict',
                array(new Link('foo', 'foo/bar', new VersionConstraint('=', '1.0.0.0'), 'requires', '1.0.0'), new Link('bar', 'bar/baz', new VersionConstraint('=', '1.0.0.0'), 'requires', '1.0.0')),
                'conflicts',
                array('bar/baz' => '1.0.0', 'foo/bar' => '1.0.0')
            )
        );
    }

    private function packageExpects($method, $value)
    {
        $this->package
            ->expects($this->any())
            ->method($method)
            ->will($this->returnValue($value));

        return $this;
    }
}
