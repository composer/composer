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

namespace Composer\Test\Policy;

use Composer\FilterList\Source\UrlSource;
use Composer\Policy\CustomListPolicyConfig;
use Composer\Policy\IgnorePackageRule;
use Composer\Policy\ListPolicyConfig;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Semver\VersionParser;
use Composer\Test\TestCase;

class CustomListPolicyConfigTest extends TestCase
{
    /**
     * @return iterable<array<mixed>>
     */
    public static function defaultProvider(): iterable
    {
        yield [[]];
        yield [true];
    }

    /**
     * @dataProvider defaultProvider
     * @param array<mixed>|bool $listConfig
     */
    public function testDefaultConfig($listConfig): void
    {
        $this->assertEquals(
            new CustomListPolicyConfig('test', true, ListPolicyConfig::AUDIT_FAIL, ListPolicyConfig::BLOCK_SCOPE_UPDATE, [], []),
            CustomListPolicyConfig::fromRawConfig('test', $listConfig, new VersionParser())
        );
    }

    /**
     * @return iterable<string, array{0: ListPolicyConfig::BLOCK_SCOPE_*, 1: ListPolicyConfig::BLOCK_SCOPE_*, 2: bool}>
     */
    public static function shouldBlockMatrixProvider(): iterable
    {
        // [configured block-scope, query scope, expected]
        yield 'all + update' => [ListPolicyConfig::BLOCK_SCOPE_ALL, ListPolicyConfig::BLOCK_SCOPE_UPDATE, true];
        yield 'all + install' => [ListPolicyConfig::BLOCK_SCOPE_ALL, ListPolicyConfig::BLOCK_SCOPE_INSTALL, true];
        yield 'update + update' => [ListPolicyConfig::BLOCK_SCOPE_UPDATE, ListPolicyConfig::BLOCK_SCOPE_UPDATE, true];
        yield 'update + install' => [ListPolicyConfig::BLOCK_SCOPE_UPDATE, ListPolicyConfig::BLOCK_SCOPE_INSTALL, false];
        yield 'install + update' => [ListPolicyConfig::BLOCK_SCOPE_INSTALL, ListPolicyConfig::BLOCK_SCOPE_UPDATE, false];
        yield 'install + install' => [ListPolicyConfig::BLOCK_SCOPE_INSTALL, ListPolicyConfig::BLOCK_SCOPE_INSTALL, true];
    }

    /**
     * @dataProvider shouldBlockMatrixProvider
     * @param ListPolicyConfig::BLOCK_SCOPE_* $configuredScope
     * @param ListPolicyConfig::BLOCK_SCOPE_* $queryScope
     */
    public function testShouldBlockHonoursConfiguredBlockScope(string $configuredScope, string $queryScope, bool $expected): void
    {
        $custom = new CustomListPolicyConfig('company-policy', true, ListPolicyConfig::AUDIT_FAIL, $configuredScope, [], []);

        self::assertSame($expected, $custom->shouldBlock($queryScope));
    }

    public function testFromRawConfig(): void
    {
        $rawListConfig = [
            'block' => false,
            'block-scope' => 'install',
            'audit' => 'report',
            'ignore' => [
                'acme/test' => 'flagged by mistake',
                'acme/test2' => ['constraint' => '1.0'],
            ],
            'sources' => [['type' => 'url', 'url' => 'https://example.com']]
        ];
        $this->assertEquals(
            new CustomListPolicyConfig('test', false, ListPolicyConfig::AUDIT_REPORT, ListPolicyConfig::BLOCK_SCOPE_INSTALL, [
                'acme/test' => [new IgnorePackageRule('acme/test', new MatchAllConstraint(), 'flagged by mistake')],
                'acme/test2' => [new IgnorePackageRule('acme/test2', (new VersionParser())->parseConstraints('1.0'))],
            ], [new UrlSource('test', 'https://example.com')]),
            CustomListPolicyConfig::fromRawConfig('test', $rawListConfig, new VersionParser())
        );
    }

    /**
     * @return iterable<array{string}>
     */
    public static function nonHttpsUrlProvider(): iterable
    {
        yield 'http' => ['http://insecure.example.org/list.json'];
        yield 'ftp' => ['ftp://example.org/list.json'];
        yield 'file' => ['file:///etc/list.json'];
        yield 'protocol-relative' => ['//example.org/list.json'];
    }

    /**
     * @dataProvider nonHttpsUrlProvider
     */
    public function testFromRawConfigRejectsNonHttpsSourceUrl(string $url): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must start with "https://"');

        CustomListPolicyConfig::fromRawConfig(
            'company-policy',
            ['sources' => [['type' => 'url', 'url' => $url]]],
            new VersionParser()
        );
    }
}
