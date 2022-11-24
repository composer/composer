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
        $config = $this->dumper->dump(self::getPackage());
        $this->assertEquals(
            [
                'name' => 'dummy/pkg',
                'version' => '1.0.0',
                'version_normalized' => '1.0.0.0',
                'type' => 'library',
            ],
            $config
        );
    }

    public function testRootPackage(): void
    {
        $package = self::getRootPackage();
        $package->setMinimumStability('dev');

        $config = $this->dumper->dump($package);
        $this->assertSame('dev', $config['minimum-stability']);
    }

    public function testDumpAbandoned(): void
    {
        $package = self::getPackage();
        $package->setAbandoned(true);
        $config = $this->dumper->dump($package);

        $this->assertTrue($config['abandoned']);
    }

    public function testDumpAbandonedReplacement(): void
    {
        $package = self::getPackage();
        $package->setAbandoned('foo/bar');
        $config = $this->dumper->dump($package);

        $this->assertSame('foo/bar', $config['abandoned']);
    }

    /**
     * @dataProvider provideKeys
     *
     * @param mixed  $value
     * @param string $method
     * @param mixed  $expectedValue
     */
    public function testKeys(string $key, $value, ?string $method = null, $expectedValue = null): void
    {
        $package = self::getRootPackage();

        // @phpstan-ignore-next-line
        $package->{'set'.ucfirst($method ?: $key)}($value);

        $config = $this->dumper->dump($package);

        $this->assertSame($expectedValue ?: $value, $config[$key]);
    }

    public static function provideKeys(): array
    {
        return [
            [
                'type',
                'library',
            ],
            [
                'time',
                $datetime = new \DateTime('2012-02-01'),
                'ReleaseDate',
                $datetime->format(DATE_RFC3339),
            ],
            [
                'authors',
                ['Nils Adermann <naderman@naderman.de>', 'Jordi Boggiano <j.boggiano@seld.be>'],
            ],
            [
                'homepage',
                'https://getcomposer.org',
            ],
            [
                'description',
                'Dependency Manager',
            ],
            [
                'keywords',
                ['package', 'dependency', 'autoload'],
                null,
                ['autoload', 'dependency', 'package'],
            ],
            [
                'bin',
                ['bin/composer'],
                'binaries',
            ],
            [
                'license',
                ['MIT'],
            ],
            [
                'autoload',
                ['psr-0' => ['Composer' => 'src/']],
            ],
            [
                'repositories',
                ['packagist' => false],
            ],
            [
                'scripts',
                ['post-update-cmd' => 'MyVendor\\MyClass::postUpdate'],
            ],
            [
                'extra',
                ['class' => 'MyVendor\\Installer'],
            ],
            [
                'archive',
                ['/foo/bar', 'baz', '!/foo/bar/baz'],
                'archiveExcludes',
                [
                    'exclude' => ['/foo/bar', 'baz', '!/foo/bar/baz'],
                ],
            ],
            [
                'require',
                ['foo/bar' => new Link('dummy/pkg', 'foo/bar', new Constraint('=', '1.0.0.0'), Link::TYPE_REQUIRE, '1.0.0')],
                'requires',
                ['foo/bar' => '1.0.0'],
            ],
            [
                'require-dev',
                ['foo/bar' => new Link('dummy/pkg', 'foo/bar', new Constraint('=', '1.0.0.0'), Link::TYPE_DEV_REQUIRE, '1.0.0')],
                'devRequires',
                ['foo/bar' => '1.0.0'],
            ],
            [
                'suggest',
                ['foo/bar' => 'very useful package'],
                'suggests',
            ],
            [
                'support',
                ['foo' => 'bar'],
            ],
            [
                'funding',
                ['type' => 'foo', 'url' => 'https://example.com'],
            ],
            [
                'require',
                [
                    'foo/bar' => new Link('dummy/pkg', 'foo/bar', new Constraint('=', '1.0.0.0'), Link::TYPE_REQUIRE, '1.0.0'),
                    'bar/baz' => new Link('dummy/pkg', 'bar/baz', new Constraint('=', '1.0.0.0'), Link::TYPE_REQUIRE, '1.0.0'),
                ],
                'requires',
                ['bar/baz' => '1.0.0', 'foo/bar' => '1.0.0'],
            ],
            [
                'require-dev',
                [
                    'foo/bar' => new Link('dummy/pkg', 'foo/bar', new Constraint('=', '1.0.0.0'), Link::TYPE_REQUIRE, '1.0.0'),
                    'bar/baz' => new Link('dummy/pkg', 'bar/baz', new Constraint('=', '1.0.0.0'), Link::TYPE_REQUIRE, '1.0.0'),
                ],
                'devRequires',
                ['bar/baz' => '1.0.0', 'foo/bar' => '1.0.0'],
            ],
            [
                'suggest',
                ['foo/bar' => 'very useful package', 'bar/baz' => 'another useful package'],
                'suggests',
                ['bar/baz' => 'another useful package', 'foo/bar' => 'very useful package'],
            ],
            [
                'provide',
                [
                    'foo/bar' => new Link('dummy/pkg', 'foo/bar', new Constraint('=', '1.0.0.0'), Link::TYPE_REQUIRE, '1.0.0'),
                    'bar/baz' => new Link('dummy/pkg', 'bar/baz', new Constraint('=', '1.0.0.0'), Link::TYPE_REQUIRE, '1.0.0'),
                ],
                'provides',
                ['bar/baz' => '1.0.0', 'foo/bar' => '1.0.0'],
            ],
            [
                'replace',
                [
                    'foo/bar' => new Link('dummy/pkg', 'foo/bar', new Constraint('=', '1.0.0.0'), Link::TYPE_REQUIRE, '1.0.0'),
                    'bar/baz' => new Link('dummy/pkg', 'bar/baz', new Constraint('=', '1.0.0.0'), Link::TYPE_REQUIRE, '1.0.0'),
                ],
                'replaces',
                ['bar/baz' => '1.0.0', 'foo/bar' => '1.0.0'],
            ],
            [
                'conflict',
                [
                    'foo/bar' => new Link('dummy/pkg', 'foo/bar', new Constraint('=', '1.0.0.0'), Link::TYPE_REQUIRE, '1.0.0'),
                    'bar/baz' => new Link('dummy/pkg', 'bar/baz', new Constraint('=', '1.0.0.0'), Link::TYPE_REQUIRE, '1.0.0'),
                ],
                'conflicts',
                ['bar/baz' => '1.0.0', 'foo/bar' => '1.0.0'],
            ],
            [
                'transport-options',
                ['ssl' => ['local_cert' => '/opt/certs/test.pem']],
                'transportOptions',
            ],
        ];
    }
}
