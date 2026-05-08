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
use Composer\Policy\AdvisoriesPolicyConfig;
use Composer\Policy\IgnoreIdRule;
use Composer\Policy\IgnorePackageRule;
use Composer\Policy\IgnoreSeverityRule;
use Composer\Policy\ListPolicyConfig;
use Composer\Policy\PolicyConfig;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Semver\VersionParser;
use Composer\Test\TestCase;

class AdvisoriesPolicyConfigTest extends TestCase
{
    /**
     * @return iterable<array<mixed>>
     */
    public static function defaultProvider(): iterable
    {
        yield [[]];
        yield [['advisories' => true]];
        yield [['advisories' => []]];
    }

    /**
     * @dataProvider defaultProvider
     * @param array<mixed> $policyConfig
     */
    public function testDefaultConfig(array $policyConfig): void
    {
        $this->assertEquals(
            new AdvisoriesPolicyConfig(true, ListPolicyConfig::AUDIT_FAIL, [], [], []),
            AdvisoriesPolicyConfig::fromRawConfig($policyConfig, [], new VersionParser())
        );
    }

    public function testFromRawConfig(): void
    {
        $rawConfig = [
            'advisories' => [
                'block' => true,
                'audit' => 'report',
                'ignore' => [
                    'acme/abandoned' => 'flagged by mistake',
                    'acme/abandoned2' => ['constraint' => '1.0'],
                ],
                'ignore-severity' => [
                    'low' => ['reason' => 'reason', 'on-block' => false, 'on-audit' => false],
                    'high' => 'ignore',
                ],
                'ignore-id' => [
                    'CVE-2024-1234' => 'flagged by mistake',
                    'CVE-2024-1235' => ['on-block' => false],
                ],
            ],
        ];
        $this->assertEquals(
            new AdvisoriesPolicyConfig(true, ListPolicyConfig::AUDIT_REPORT, [
                'acme/abandoned' => [new IgnorePackageRule('acme/abandoned', new MatchAllConstraint(), 'flagged by mistake')],
                'acme/abandoned2' => [new IgnorePackageRule('acme/abandoned2', (new VersionParser())->parseConstraints('1.0'))],
            ], [
                'CVE-2024-1234' => new IgnoreIdRule('CVE-2024-1234', 'flagged by mistake'),
                'CVE-2024-1235' => new IgnoreIdRule('CVE-2024-1235', null, false),
            ], [
                'low' => new IgnoreSeverityRule('low', 'reason', false, false),
                'high' => new IgnoreSeverityRule('high', 'ignore', true, true)
            ]),
            AdvisoriesPolicyConfig::fromRawConfig($rawConfig, [], new VersionParser())
        );
    }

    public function testShouldBlockNeverAppliesToInstallScope(): void
    {
        $advisories = new AdvisoriesPolicyConfig(true, ListPolicyConfig::AUDIT_FAIL, [], [], []);

        self::assertTrue($advisories->shouldBlock(ListPolicyConfig::BLOCK_SCOPE_UPDATE));
        self::assertFalse($advisories->shouldBlock(ListPolicyConfig::BLOCK_SCOPE_INSTALL));
    }

    public function testShouldBlockReturnsFalseWhenBlockIsOff(): void
    {
        $advisories = new AdvisoriesPolicyConfig(false, ListPolicyConfig::AUDIT_FAIL, [], [], []);

        self::assertFalse($advisories->shouldBlock(ListPolicyConfig::BLOCK_SCOPE_UPDATE));
        self::assertFalse($advisories->shouldBlock(ListPolicyConfig::BLOCK_SCOPE_INSTALL));
    }

    public function testFromAuditConfig(): void
    {
        $auditConfig = [
            'block' => true,
            'ignore' => [
                'acme/abandoned' => 'flagged by mistake',
                'acme/abandoned2' => ['apply' => 'block'],
                'CVE-2024-1234' => 'flagged by mistake',
                'CVE-2024-1235' => ['apply' => 'audit'],
            ],
            'ignore-severity' => [
                'low' => ['apply' => 'block']
            ]
        ];
        $this->assertEquals(
            new AdvisoriesPolicyConfig(true, ListPolicyConfig::AUDIT_FAIL, [
                'acme/abandoned' => [new IgnorePackageRule('acme/abandoned', new MatchAllConstraint(), 'flagged by mistake')],
                'acme/abandoned2' => [new IgnorePackageRule('acme/abandoned2', new MatchAllConstraint(), null, true, false)],
            ], [
                'CVE-2024-1234' => new IgnoreIdRule('CVE-2024-1234', 'flagged by mistake'),
                'CVE-2024-1235' => new IgnoreIdRule('CVE-2024-1235', null, false, true),
            ], [
                'low' => new IgnoreSeverityRule('low', null, true, false),
            ]),
            AdvisoriesPolicyConfig::fromRawConfig([], $auditConfig, new VersionParser())
        );
    }

    public function testLegacyAuditIgnoreSimpleArray(): void
    {
        $config = new Config();
        $config->merge(['config' => ['audit' => [
            'ignore' => ['CVE-2024-1234', 'CVE-2024-5678'],
        ]]]);

        $advisories = PolicyConfig::fromConfig($config)->advisories;

        self::assertSame(['CVE-2024-1234' => null, 'CVE-2024-5678' => null], $advisories->getIgnoreListForOperation('audit'));
        self::assertSame(['CVE-2024-1234' => null, 'CVE-2024-5678' => null], $advisories->getIgnoreListForOperation('block'));
    }

    public function testLegacyAuditIgnoreApplyAuditOnly(): void
    {
        $config = new Config();
        $config->merge(['config' => ['audit' => [
            'ignore' => [
                'CVE-2024-1234' => ['apply' => 'audit', 'reason' => 'Only ignore for auditing'],
            ],
        ]]]);

        $advisories = PolicyConfig::fromConfig($config)->advisories;

        self::assertSame(['CVE-2024-1234' => 'Only ignore for auditing'], $advisories->getIgnoreListForOperation('audit'));
        self::assertSame([], $advisories->getIgnoreListForOperation('block'));
    }

    public function testLegacyAuditIgnoreApplyBlockOnly(): void
    {
        $config = new Config();
        $config->merge(['config' => ['audit' => [
            'ignore' => [
                'CVE-2024-1234' => ['apply' => 'block', 'reason' => 'Only ignore for blocking'],
            ],
        ]]]);

        $advisories = PolicyConfig::fromConfig($config)->advisories;

        self::assertSame([], $advisories->getIgnoreListForOperation('audit'));
        self::assertSame(['CVE-2024-1234' => 'Only ignore for blocking'], $advisories->getIgnoreListForOperation('block'));
    }

    public function testLegacyAuditIgnoreMixedFormats(): void
    {
        $config = new Config();
        $config->merge(['config' => ['audit' => [
            'ignore' => [
                'CVE-2024-1234',
                'CVE-2024-5678' => 'Simple reason',
                'CVE-2024-9999' => ['apply' => 'audit', 'reason' => 'Detailed reason'],
                'CVE-2024-8888' => ['apply' => 'block'],
            ],
        ]]]);

        $advisories = PolicyConfig::fromConfig($config)->advisories;

        self::assertSame([
            'CVE-2024-1234' => null,
            'CVE-2024-5678' => 'Simple reason',
            'CVE-2024-9999' => 'Detailed reason',
        ], $advisories->getIgnoreListForOperation('audit'));
        self::assertSame([
            'CVE-2024-1234' => null,
            'CVE-2024-5678' => 'Simple reason',
            'CVE-2024-8888' => null,
        ], $advisories->getIgnoreListForOperation('block'));
    }

    public function testLegacyAuditIgnoreSeveritySimpleArray(): void
    {
        $config = new Config();
        $config->merge(['config' => ['audit' => [
            'ignore-severity' => ['low', 'medium'],
        ]]]);

        $advisories = PolicyConfig::fromConfig($config)->advisories;

        self::assertSame(['low' => null, 'medium' => null], $advisories->getIgnoreSeverityForOperation('audit'));
        self::assertSame(['low' => null, 'medium' => null], $advisories->getIgnoreSeverityForOperation('block'));
    }

    public function testLegacyAuditIgnoreSeverityDetailedFormat(): void
    {
        $config = new Config();
        $config->merge(['config' => ['audit' => [
            'ignore-severity' => [
                'low' => ['apply' => 'audit', 'reason' => 'We accept low severity issues'],
                'medium' => ['apply' => 'block'],
            ],
        ]]]);

        $advisories = PolicyConfig::fromConfig($config)->advisories;

        self::assertSame(['low' => 'We accept low severity issues'], $advisories->getIgnoreSeverityForOperation('audit'));
        self::assertSame(['medium' => null], $advisories->getIgnoreSeverityForOperation('block'));
    }

    public function testLegacyAuditIgnoreInvalidApplyValue(): void
    {
        self::expectException(\InvalidArgumentException::class);
        self::expectExceptionMessage("Invalid 'apply' value for 'CVE-2024-1234': invalid. Expected 'audit', 'block', or 'all'.");

        $config = new Config();
        $config->merge(['config' => ['audit' => [
            'ignore' => [
                'CVE-2024-1234' => ['apply' => 'invalid'],
            ],
        ]]]);

        PolicyConfig::fromConfig($config);
    }

    public function testGetIgnoreListForOperationMergesMultiRuleReasons(): void
    {
        $config = new Config();
        $config->merge(['config' => ['policy' => [
            'advisories' => [
                'ignore' => [
                    'vendor/multi' => [
                        ['constraint' => '^1.0', 'reason' => 'v1 patched'],
                        ['constraint' => '^2.0', 'reason' => 'v2 mitigated'],
                    ],
                ],
            ],
        ]]]);

        $advisories = PolicyConfig::fromConfig($config)->advisories;

        $auditList = $advisories->getIgnoreListForOperation('audit');
        self::assertArrayHasKey('vendor/multi', $auditList);
        $reason = $auditList['vendor/multi'];
        self::assertNotNull($reason, 'Reason must not be silently dropped');
        self::assertStringContainsString('v1 patched', $reason);
        self::assertStringContainsString('v2 mitigated', $reason);

        $blockList = $advisories->getIgnoreListForOperation('block');
        self::assertArrayHasKey('vendor/multi', $blockList);
        $blockReason = $blockList['vendor/multi'];
        self::assertNotNull($blockReason);
        self::assertStringContainsString('v1 patched', $blockReason);
        self::assertStringContainsString('v2 mitigated', $blockReason);
    }

    public function testGetIgnoreListForOperationPrefersConcreteReasonOverNull(): void
    {
        $config = new Config();
        $config->merge(['config' => ['policy' => [
            'advisories' => [
                'ignore' => [
                    'vendor/mixed' => [
                        ['constraint' => '^1.0'],
                        ['constraint' => '^2.0', 'reason' => 'v2 mitigated'],
                    ],
                ],
            ],
        ]]]);

        $advisories = PolicyConfig::fromConfig($config)->advisories;

        self::assertSame('v2 mitigated', $advisories->getIgnoreListForOperation('audit')['vendor/mixed']);
    }

    public function testWithIgnoreSeverityAddsAuditScopedRulesForNewSeverities(): void
    {
        $advisories = AdvisoriesPolicyConfig::disabled();

        $updated = $advisories->withIgnoreSeverity(['low', 'medium']);

        self::assertSame(['low' => null, 'medium' => null], $updated->getIgnoreSeverityForOperation('audit'));
        self::assertSame([], $updated->getIgnoreSeverityForOperation('block'));
    }

    public function testWithIgnoreSeverityPreservesExistingRulesAndReasons(): void
    {
        $config = new Config();
        $config->merge(['config' => ['policy' => [
            'advisories' => [
                'ignore-severity' => [
                    'low' => ['reason' => 'configured low', 'on-block' => false, 'on-audit' => true],
                ],
            ],
        ]]]);
        $advisories = PolicyConfig::fromConfig($config)->advisories;

        $updated = $advisories->withIgnoreSeverity(['low', 'medium']);

        $auditRules = $updated->getIgnoreSeverityForOperation('audit');
        self::assertSame('configured low', $auditRules['low']);
        self::assertNull($auditRules['medium']);
    }
}
