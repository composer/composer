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

use Composer\Config;
use Composer\Policy\AbandonedPolicyConfig;
use Composer\Policy\IgnorePackageRule;
use Composer\Policy\ListPolicyConfig;
use Composer\Policy\PolicyConfig;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Semver\VersionParser;
use Composer\Test\TestCase;

class AbandonedPolicyConfigTest extends TestCase
{
    /**
     * @return iterable<array<mixed>>
     */
    public static function defaultProvider(): iterable
    {
        yield [[]];
        yield [['abandoned' => true]];
        yield [['abandoned' => []]];
    }

    /**
     * @dataProvider defaultProvider
     * @param array<mixed> $policyConfig
     */
    public function testDefaultConfig(array $policyConfig): void
    {
        $this->assertEquals(
            new AbandonedPolicyConfig(false, ListPolicyConfig::AUDIT_FAIL, []),
            AbandonedPolicyConfig::fromRawConfig($policyConfig, [], new VersionParser())
        );
    }

    public function testFromRawConfig(): void
    {
        $rawConfig = [
            'abandoned' => [
                'block' => true,
                'audit' => 'report',
                'ignore' => [
                    'acme/abandoned' => 'flagged by mistake',
                    'acme/abandoned2' => ['constraint' => '1.0'],
                ],
            ],
        ];
        $this->assertEquals(
            new AbandonedPolicyConfig(true, ListPolicyConfig::AUDIT_REPORT, [
                'acme/abandoned' => [new IgnorePackageRule('acme/abandoned', new MatchAllConstraint(), 'flagged by mistake')],
                'acme/abandoned2' => [new IgnorePackageRule('acme/abandoned2', (new VersionParser())->parseConstraints('1.0'))],
            ]),
            AbandonedPolicyConfig::fromRawConfig($rawConfig, [], new VersionParser())
        );
    }

    public function testFromAuditConfig(): void
    {
        $auditConfig = [
            'block-abandoned' => true,
            'abandoned' => 'report',
            'ignore-abandoned' => [
                'acme/abandoned' => 'flagged by mistake',
                'acme/abandoned2' => ['apply' => 'block'],
            ],
        ];
        $this->assertEquals(
            new AbandonedPolicyConfig(true, ListPolicyConfig::AUDIT_REPORT, [
                'acme/abandoned' => [new IgnorePackageRule('acme/abandoned', new MatchAllConstraint(), 'flagged by mistake')],
                'acme/abandoned2' => [new IgnorePackageRule('acme/abandoned2', new MatchAllConstraint(), null, true, false)],
            ]),
            AbandonedPolicyConfig::fromRawConfig([], $auditConfig, new VersionParser())
        );
    }

    public function testLegacyIgnoreAbandonedSimpleArray(): void
    {
        $config = new Config();
        $config->merge(['config' => ['audit' => [
            'ignore-abandoned' => ['vendor/package1', 'vendor/package2'],
        ]]]);

        $abandoned = PolicyConfig::fromConfig($config)->abandoned;

        self::assertSame(['vendor/package1' => null, 'vendor/package2' => null], $abandoned->getFlatIgnoreForOperation('audit'));
        self::assertSame(['vendor/package1' => null, 'vendor/package2' => null], $abandoned->getFlatIgnoreForOperation('block'));
    }

    public function testLegacyIgnoreAbandonedDetailedFormat(): void
    {
        $config = new Config();
        $config->merge(['config' => ['audit' => [
            'ignore-abandoned' => [
                'vendor/package1' => ['apply' => 'audit', 'reason' => 'Report but do not block'],
                'vendor/package2' => ['apply' => 'block', 'reason' => 'Block but do not report'],
            ],
        ]]]);

        $abandoned = PolicyConfig::fromConfig($config)->abandoned;

        self::assertSame(['vendor/package1' => 'Report but do not block'], $abandoned->getFlatIgnoreForOperation('audit'));
        self::assertSame(['vendor/package2' => 'Block but do not report'], $abandoned->getFlatIgnoreForOperation('block'));
    }

    public function testGetFlatIgnoreForOperationMergesMultiRuleReasons(): void
    {
        $config = new Config();
        $config->merge(['config' => ['policy' => [
            'abandoned' => [
                'ignore' => [
                    'vendor/multi-abandoned' => [
                        ['constraint' => '^1.0', 'reason' => 'fork ready'],
                        ['constraint' => '^2.0', 'reason' => 'maintained downstream'],
                    ],
                ],
            ],
        ]]]);

        $abandoned = PolicyConfig::fromConfig($config)->abandoned;

        $reason = $abandoned->getFlatIgnoreForOperation('audit')['vendor/multi-abandoned'];
        self::assertNotNull($reason);
        self::assertStringContainsString('fork ready', $reason);
        self::assertStringContainsString('maintained downstream', $reason);
    }
}
