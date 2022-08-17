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

namespace Composer\Test\Package\Loader;

use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\Link;
use Composer\Test\TestCase;

class ArrayLoaderTest extends TestCase
{
    /**
     * @var ArrayLoader
     */
    private $loader;

    public function setUp(): void
    {
        $this->loader = new ArrayLoader(null);
    }

    public function testSelfVersion(): void
    {
        $config = [
            'name' => 'A',
            'version' => '1.2.3.4',
            'replace' => [
                'foo' => 'self.version',
            ],
        ];

        $package = $this->loader->load($config);
        $replaces = $package->getReplaces();
        $this->assertEquals('== 1.2.3.4', (string) $replaces['foo']->getConstraint());
    }

    public function testTypeDefault(): void
    {
        $config = [
            'name' => 'A',
            'version' => '1.0',
        ];

        $package = $this->loader->load($config);
        $this->assertEquals('library', $package->getType());

        $config = [
            'name' => 'A',
            'version' => '1.0',
            'type' => 'foo',
        ];

        $package = $this->loader->load($config);
        $this->assertEquals('foo', $package->getType());
    }

    public function testNormalizedVersionOptimization(): void
    {
        $config = [
            'name' => 'A',
            'version' => '1.2.3',
        ];

        $package = $this->loader->load($config);
        $this->assertEquals('1.2.3.0', $package->getVersion());

        $config = [
            'name' => 'A',
            'version' => '1.2.3',
            'version_normalized' => '1.2.3.4',
        ];

        $package = $this->loader->load($config);
        $this->assertEquals('1.2.3.4', $package->getVersion());
    }

    public function parseDumpProvider(): array
    {
        $validConfig = [
            'name' => 'A/B',
            'version' => '1.2.3',
            'version_normalized' => '1.2.3.0',
            'description' => 'Foo bar',
            'type' => 'library',
            'keywords' => ['a', 'b', 'c'],
            'homepage' => 'http://example.com',
            'license' => ['MIT', 'GPLv3'],
            'authors' => [
                ['name' => 'Bob', 'email' => 'bob@example.org', 'homepage' => 'example.org', 'role' => 'Developer'],
            ],
            'funding' => [
                ['type' => 'example', 'url' => 'https://example.org/fund'],
            ],
            'require' => [
                'foo/bar' => '1.0',
            ],
            'require-dev' => [
                'foo/baz' => '1.0',
            ],
            'replace' => [
                'foo/qux' => '1.0',
            ],
            'conflict' => [
                'foo/quux' => '1.0',
            ],
            'provide' => [
                'foo/quuux' => '1.0',
            ],
            'autoload' => [
                'psr-0' => ['Ns\Prefix' => 'path'],
                'classmap' => ['path', 'path2'],
            ],
            'include-path' => ['path3', 'path4'],
            'target-dir' => 'some/prefix',
            'extra' => ['random' => ['things' => 'of', 'any' => 'shape']],
            'bin' => ['bin1', 'bin/foo'],
            'archive' => [
                'exclude' => ['/foo/bar', 'baz', '!/foo/bar/baz'],
            ],
            'transport-options' => ['ssl' => ['local_cert' => '/opt/certs/test.pem']],
            'abandoned' => 'foo/bar',
        ];

        return [[$validConfig]];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    protected function fixConfigWhenLoadConfigIsFalse(array $config): array
    {
        $expectedConfig = $config;
        unset($expectedConfig['transport-options']);

        return $expectedConfig;
    }

    /**
     * The default parser should default to loading the config as this
     * allows require-dev libraries to have transport options included.
     *
     * @dataProvider parseDumpProvider
     *
     * @param array<string, mixed> $config
     */
    public function testParseDumpDefaultLoadConfig(array $config): void
    {
        $package = $this->loader->load($config);
        $dumper = new ArrayDumper;
        $expectedConfig = $this->fixConfigWhenLoadConfigIsFalse($config);
        $this->assertEquals($expectedConfig, $dumper->dump($package));
    }

    /**
     * @dataProvider parseDumpProvider
     *
     * @param array<string, mixed> $config
     */
    public function testParseDumpTrueLoadConfig(array $config): void
    {
        $loader = new ArrayLoader(null, true);
        $package = $loader->load($config);
        $dumper = new ArrayDumper;
        $expectedConfig = $config;
        $this->assertEquals($expectedConfig, $dumper->dump($package));
    }

    /**
     * @dataProvider parseDumpProvider
     *
     * @param array<string, mixed> $config
     */
    public function testParseDumpFalseLoadConfig(array $config): void
    {
        $loader = new ArrayLoader(null, false);
        $package = $loader->load($config);
        $dumper = new ArrayDumper;
        $expectedConfig = $this->fixConfigWhenLoadConfigIsFalse($config);
        $this->assertEquals($expectedConfig, $dumper->dump($package));
    }

    public function testPackageWithBranchAlias(): void
    {
        $config = [
            'name' => 'A',
            'version' => 'dev-master',
            'extra' => ['branch-alias' => ['dev-master' => '1.0.x-dev']],
        ];

        $package = $this->loader->load($config);

        $this->assertInstanceOf('Composer\Package\AliasPackage', $package);
        $this->assertEquals('1.0.x-dev', $package->getPrettyVersion());

        $config = [
            'name' => 'A',
            'version' => 'dev-master',
            'extra' => ['branch-alias' => ['dev-master' => '1.0-dev']],
        ];

        $package = $this->loader->load($config);

        $this->assertInstanceOf('Composer\Package\AliasPackage', $package);
        $this->assertEquals('1.0.x-dev', $package->getPrettyVersion());

        $config = [
            'name' => 'B',
            'version' => '4.x-dev',
            'extra' => ['branch-alias' => ['4.x-dev' => '4.0.x-dev']],
        ];

        $package = $this->loader->load($config);

        $this->assertInstanceOf('Composer\Package\AliasPackage', $package);
        $this->assertEquals('4.0.x-dev', $package->getPrettyVersion());

        $config = [
            'name' => 'B',
            'version' => '4.x-dev',
            'extra' => ['branch-alias' => ['4.x-dev' => '4.0-dev']],
        ];

        $package = $this->loader->load($config);

        $this->assertInstanceOf('Composer\Package\AliasPackage', $package);
        $this->assertEquals('4.0.x-dev', $package->getPrettyVersion());

        $config = [
            'name' => 'C',
            'version' => '4.x-dev',
            'extra' => ['branch-alias' => ['4.x-dev' => '3.4.x-dev']],
        ];

        $package = $this->loader->load($config);

        $this->assertInstanceOf('Composer\Package\CompletePackage', $package);
        $this->assertEquals('4.x-dev', $package->getPrettyVersion());
    }

    public function testAbandoned(): void
    {
        $config = [
            'name' => 'A',
            'version' => '1.2.3.4',
            'abandoned' => 'foo/bar',
        ];

        $package = $this->loader->load($config);
        $this->assertTrue($package->isAbandoned());
        $this->assertEquals('foo/bar', $package->getReplacementPackage());
    }

    public function testNotAbandoned(): void
    {
        $config = [
            'name' => 'A',
            'version' => '1.2.3.4',
        ];

        $package = $this->loader->load($config);
        $this->assertFalse($package->isAbandoned());
    }

    public function providePluginApiVersions(): array
    {
        return [
            ['1.0'],
            ['1.0.0'],
            ['1.0.0.0'],
            ['1'],
            ['=1.0.0'],
            ['==1.0'],
            ['~1.0.0'],
            ['*'],
            ['3.0.*'],
            ['@stable'],
            ['1.0.0@stable'],
            ['^5.1'],
            ['>=1.0.0 <2.5'],
            ['x'],
            ['1.0.0-dev'],
        ];
    }

    /**
     * @dataProvider providePluginApiVersions
     */
    public function testPluginApiVersionAreKeptAsDeclared(string $apiVersion): void
    {
        $links = $this->loader->parseLinks('Plugin', '9.9.9', Link::TYPE_REQUIRE, ['composer-plugin-api' => $apiVersion]);

        $this->assertArrayHasKey('composer-plugin-api', $links);
        $this->assertSame($apiVersion, $links['composer-plugin-api']->getConstraint()->getPrettyString());
    }

    public function testPluginApiVersionDoesSupportSelfVersion(): void
    {
        $links = $this->loader->parseLinks('Plugin', '6.6.6', Link::TYPE_REQUIRE, ['composer-plugin-api' => 'self.version']);

        $this->assertArrayHasKey('composer-plugin-api', $links);
        $this->assertSame('6.6.6', $links['composer-plugin-api']->getConstraint()->getPrettyString());
    }

    public function testParseLinksIntegerTarget(): void
    {
        $links = $this->loader->parseLinks('Plugin', '9.9.9', Link::TYPE_REQUIRE, ['1' => 'dev-main']);

        $this->assertArrayHasKey('1', $links);
    }

    public function testNoneStringVersion(): void
    {
        $config = [
            'name' => 'acme/package',
            'version' => 1,
        ];

        $package = $this->loader->load($config);
        $this->assertSame('1', $package->getPrettyVersion());
    }

    public function testNoneStringSourceDistReference(): void
    {
        $config = [
            'name' => 'acme/package',
            'version' => 'dev-main',
            'source' => [
                'type' => 'svn',
                'url' => 'https://example.org/',
                'reference' => 2019,
            ],
            'dist' => [
                'type' => 'zip',
                'url' => 'https://example.org/',
                'reference' => 2019,
            ],
        ];

        $package = $this->loader->load($config);
        $this->assertSame('2019', $package->getSourceReference());
        $this->assertSame('2019', $package->getDistReference());
    }

    public function testBranchAliasIntegerIndex(): void
    {
        $config = [
            'name' => 'acme/package',
            'version' => 'dev-1',
            'extra' => [
                'branch-alias' => [
                    '1' => '1.3-dev',
                ],
            ],
            'dist' => [
                'type' => 'zip',
                'url' => 'https://example.org/',
            ],
        ];

        $this->assertNull($this->loader->getBranchAlias($config));
    }
}
