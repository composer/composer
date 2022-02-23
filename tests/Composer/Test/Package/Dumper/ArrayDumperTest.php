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
use Composer\Semver\Constraint\Constraint;
use Composer\Test\TestCase;

class ArrayDumperTest extends TestCase
{
    /**
     * @var ArrayDumper
     */
    private $dumper;

    public function setUp(): void
    {
        $this->dumper = new ArrayDumper();
    }

    public function testRequiredInformation(): void
    {
        $config = $this->dumper->dump($this->getPackage());
        $this->assertEquals(
            array(
                'name' => 'dummy/pkg',
                'version' => '1.0.0',
                'version_normalized' => '1.0.0.0',
                'type' => 'library',
            ),
            $config
        );
    }

    public function testRootPackage(): void
    {
        $package = $this->getRootPackage();
        $package->setMinimumStability('dev');

        $config = $this->dumper->dump($package);
        $this->assertSame('dev', $config['minimum-stability']);
    }

    public function testDumpAbandoned(): void
    {
        $package = $this->getPackage();
        $package->setAbandoned(true);
        $config = $this->dumper->dump($package);

        $this->assertTrue($config['abandoned']);
    }

    public function testDumpAbandonedReplacement(): void
    {
        $package = $this->getPackage();
        $package->setAbandoned('foo/bar');
        $config = $this->dumper->dump($package);

        $this->assertSame('foo/bar', $config['abandoned']);
    }

    /**
     * @dataProvider provideKeys
     *
     * @param string $key
     * @param mixed  $value
     * @param string $method
     * @param mixed  $expectedValue
     */
    public function testKeys(string $key, $value, string $method = null, $expectedValue = null): void
    {
        $package = $this->getRootPackage();

        // @phpstan-ignore-next-line
        $package->{'set'.ucfirst($method ?: $key)}($value);

        $config = $this->dumper->dump($package);

        $this->assertSame($expectedValue ?: $value, $config[$key]);
    }

    public function provideKeys(): array
    {
        return array(
            array(
                'type',
                'library',
            ),
            array(
                'time',
                $datetime = new \DateTime('2012-02-01'),
                'ReleaseDate',
                $datetime->format(DATE_RFC3339),
            ),
            array(
                'authors',
                array('Nils Adermann <naderman@naderman.de>', 'Jordi Boggiano <j.boggiano@seld.be>'),
            ),
            array(
                'homepage',
                'https://getcomposer.org',
            ),
            array(
                'description',
                'Dependency Manager',
            ),
            array(
                'keywords',
                array('package', 'dependency', 'autoload'),
                null,
                array('autoload', 'dependency', 'package'),
            ),
            array(
                'bin',
                array('bin/composer'),
                'binaries',
            ),
            array(
                'license',
                array('MIT'),
            ),
            array(
                'autoload',
                array('psr-0' => array('Composer' => 'src/')),
            ),
            array(
                'repositories',
                array('packagist' => false),
            ),
            array(
                'scripts',
                array('post-update-cmd' => 'MyVendor\\MyClass::postUpdate'),
            ),
            array(
                'extra',
                array('class' => 'MyVendor\\Installer'),
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
                array('foo/bar' => new Link('dummy/pkg', 'foo/bar', new Constraint('=', '1.0.0.0'), Link::TYPE_REQUIRE, '1.0.0')),
                'requires',
                array('foo/bar' => '1.0.0'),
            ),
            array(
                'require-dev',
                array('foo/bar' => new Link('dummy/pkg', 'foo/bar', new Constraint('=', '1.0.0.0'), Link::TYPE_DEV_REQUIRE, '1.0.0')),
                'devRequires',
                array('foo/bar' => '1.0.0'),
            ),
            array(
                'suggest',
                array('foo/bar' => 'very useful package'),
                'suggests',
            ),
            array(
                'support',
                array('foo' => 'bar'),
            ),
            array(
                'funding',
                array('type' => 'foo', 'url' => 'https://example.com'),
            ),
            array(
                'require',
                array(
                    'foo/bar' => new Link('dummy/pkg', 'foo/bar', new Constraint('=', '1.0.0.0'), Link::TYPE_REQUIRE, '1.0.0'),
                    'bar/baz' => new Link('dummy/pkg', 'bar/baz', new Constraint('=', '1.0.0.0'), Link::TYPE_REQUIRE, '1.0.0'),
                ),
                'requires',
                array('bar/baz' => '1.0.0', 'foo/bar' => '1.0.0'),
            ),
            array(
                'require-dev',
                array(
                    'foo/bar' => new Link('dummy/pkg', 'foo/bar', new Constraint('=', '1.0.0.0'), Link::TYPE_REQUIRE, '1.0.0'),
                    'bar/baz' => new Link('dummy/pkg', 'bar/baz', new Constraint('=', '1.0.0.0'), Link::TYPE_REQUIRE, '1.0.0'),
                ),
                'devRequires',
                array('bar/baz' => '1.0.0', 'foo/bar' => '1.0.0'),
            ),
            array(
                'suggest',
                array('foo/bar' => 'very useful package', 'bar/baz' => 'another useful package'),
                'suggests',
                array('bar/baz' => 'another useful package', 'foo/bar' => 'very useful package'),
            ),
            array(
                'provide',
                array(
                    'foo/bar' => new Link('dummy/pkg', 'foo/bar', new Constraint('=', '1.0.0.0'), Link::TYPE_REQUIRE, '1.0.0'),
                    'bar/baz' => new Link('dummy/pkg', 'bar/baz', new Constraint('=', '1.0.0.0'), Link::TYPE_REQUIRE, '1.0.0'),
                ),
                'provides',
                array('bar/baz' => '1.0.0', 'foo/bar' => '1.0.0'),
            ),
            array(
                'replace',
                array(
                    'foo/bar' => new Link('dummy/pkg', 'foo/bar', new Constraint('=', '1.0.0.0'), Link::TYPE_REQUIRE, '1.0.0'),
                    'bar/baz' => new Link('dummy/pkg', 'bar/baz', new Constraint('=', '1.0.0.0'), Link::TYPE_REQUIRE, '1.0.0'),
                ),
                'replaces',
                array('bar/baz' => '1.0.0', 'foo/bar' => '1.0.0'),
            ),
            array(
                'conflict',
                array(
                    'foo/bar' => new Link('dummy/pkg', 'foo/bar', new Constraint('=', '1.0.0.0'), Link::TYPE_REQUIRE, '1.0.0'),
                    'bar/baz' => new Link('dummy/pkg', 'bar/baz', new Constraint('=', '1.0.0.0'), Link::TYPE_REQUIRE, '1.0.0'),
                ),
                'conflicts',
                array('bar/baz' => '1.0.0', 'foo/bar' => '1.0.0'),
            ),
            array(
                'transport-options',
                array('ssl' => array('local_cert' => '/opt/certs/test.pem')),
                'transportOptions',
            ),
        );
    }
}
