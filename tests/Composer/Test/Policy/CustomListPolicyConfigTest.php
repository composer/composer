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
            new CustomListPolicyConfig('test', true, ListPolicyConfig::AUDIT_FAIL, [], []),
            CustomListPolicyConfig::fromRawConfig('test', $listConfig, new VersionParser())
        );
    }

    public function testFromRawConfig(): void
    {
        $rawListConfig = [
            'block' => false,
            'audit' => 'report',
            'ignore' => [
                'acme/test' => 'flagged by mistake',
                'acme/test2' => ['constraint' => '1.0'],
            ],
            'sources' => [['type' => 'url', 'url' => 'https://example.com']]
        ];
        $this->assertEquals(
            new CustomListPolicyConfig('test', false, ListPolicyConfig::AUDIT_REPORT, [
                'acme/test' => [new IgnorePackageRule('acme/test', new MatchAllConstraint(), 'flagged by mistake')],
                'acme/test2' => [new IgnorePackageRule('acme/test2', (new VersionParser())->parseConstraints('1.0'))],
            ], [new UrlSource('test', 'https://example.com')]),
            CustomListPolicyConfig::fromRawConfig('test', $rawListConfig, new VersionParser())
        );
    }
}
