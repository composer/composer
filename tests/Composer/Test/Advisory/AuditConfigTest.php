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

namespace Composer\Test\Advisory;

use Composer\Advisory\AuditConfig;
use Composer\Advisory\Auditor;
use Composer\Config;
use Composer\Policy\PolicyConfig;
use Composer\Test\TestCase;

class AuditConfigTest extends TestCase
{
    public function testSimpleFormat(): void
    {
        $config = new Config();
        $config->merge([
            'config' => [
                'audit' => [
                    'ignore' => ['CVE-2024-1234', 'CVE-2024-5678'],
                ],
            ],
        ]);

        $auditConfig = AuditConfig::fromConfig($config);

        $this->assertSame(['CVE-2024-1234' => null, 'CVE-2024-5678' => null], $auditConfig->ignoreListForAudit);
        $this->assertSame(['CVE-2024-1234' => null, 'CVE-2024-5678' => null], $auditConfig->ignoreListForBlocking);
    }

    public function testDetailedFormatAuditOnly(): void
    {
        $config = new Config();
        $config->merge([
            'config' => [
                'audit' => [
                    'ignore' => [
                        'CVE-2024-1234' => [
                            'apply' => 'audit',
                            'reason' => 'Only ignore for auditing',
                        ],
                    ],
                ],
            ],
        ]);

        $auditConfig = AuditConfig::fromConfig($config);

        $this->assertSame(['CVE-2024-1234' => 'Only ignore for auditing'], $auditConfig->ignoreListForAudit);
        $this->assertSame([], $auditConfig->ignoreListForBlocking);
    }

    public function testDetailedFormatBlockOnly(): void
    {
        $config = new Config();
        $config->merge([
            'config' => [
                'audit' => [
                    'ignore' => [
                        'CVE-2024-1234' => [
                            'apply' => 'block',
                            'reason' => 'Only ignore for blocking',
                        ],
                    ],
                ],
            ],
        ]);

        $auditConfig = AuditConfig::fromConfig($config);

        $this->assertSame([], $auditConfig->ignoreListForAudit);
        $this->assertSame(['CVE-2024-1234' => 'Only ignore for blocking'], $auditConfig->ignoreListForBlocking);
    }

    public function testMixedFormats(): void
    {
        $config = new Config();
        $config->merge([
            'config' => [
                'audit' => [
                    'ignore' => [
                        'CVE-2024-1234',
                        'CVE-2024-5678' => 'Simple reason',
                        'CVE-2024-9999' => [
                            'apply' => 'audit',
                            'reason' => 'Detailed reason',
                        ],
                        'CVE-2024-8888' => [
                            'apply' => 'block',
                        ],
                    ],
                ],
            ],
        ]);

        $auditConfig = AuditConfig::fromConfig($config);

        $this->assertSame([
            'CVE-2024-1234' => null,
            'CVE-2024-5678' => 'Simple reason',
            'CVE-2024-9999' => 'Detailed reason',
        ], $auditConfig->ignoreListForAudit);
        $this->assertSame([
            'CVE-2024-1234' => null,
            'CVE-2024-5678' => 'Simple reason',
            'CVE-2024-8888' => null,
        ], $auditConfig->ignoreListForBlocking);
    }

    public function testIgnoreSeveritySimpleArray(): void
    {
        $config = new Config();
        $config->merge([
            'config' => [
                'audit' => [
                    'ignore-severity' => ['low', 'medium'],
                ],
            ],
        ]);

        $auditConfig = AuditConfig::fromConfig($config);

        $this->assertSame(['low' => null, 'medium' => null], $auditConfig->ignoreSeverityForAudit);
        $this->assertSame(['low' => null, 'medium' => null], $auditConfig->ignoreSeverityForBlocking);
    }

    public function testIgnoreSeverityDetailedFormat(): void
    {
        $config = new Config();
        $config->merge([
            'config' => [
                'audit' => [
                    'ignore-severity' => [
                        'low' => [
                            'apply' => 'audit',
                            'reason' => 'We accept low severity issues',
                        ],
                        'medium' => [
                            'apply' => 'block',
                        ],
                    ],
                ],
            ],
        ]);

        $auditConfig = AuditConfig::fromConfig($config);

        $this->assertSame(['low' => 'We accept low severity issues'], $auditConfig->ignoreSeverityForAudit);
        $this->assertSame(['medium' => null], $auditConfig->ignoreSeverityForBlocking);
    }

    public function testIgnoreAbandonedSimpleFormat(): void
    {
        $config = new Config();
        $config->merge([
            'config' => [
                'audit' => [
                    'ignore-abandoned' => ['vendor/package1', 'vendor/package2'],
                ],
            ],
        ]);

        $auditConfig = AuditConfig::fromConfig($config);

        $this->assertSame(['vendor/package1' => null, 'vendor/package2' => null], $auditConfig->ignoreAbandonedForAudit);
        $this->assertSame(['vendor/package1' => null, 'vendor/package2' => null], $auditConfig->ignoreAbandonedForBlocking);
    }

    public function testIgnoreAbandonedDetailedFormat(): void
    {
        $config = new Config();
        $config->merge([
            'config' => [
                'audit' => [
                    'ignore-abandoned' => [
                        'vendor/package1' => [
                            'apply' => 'audit',
                            'reason' => 'Report but do not block',
                        ],
                        'vendor/package2' => [
                            'apply' => 'block',
                            'reason' => 'Block but do not report',
                        ],
                    ],
                ],
            ],
        ]);

        $auditConfig = AuditConfig::fromConfig($config);

        $this->assertSame(['vendor/package1' => 'Report but do not block'], $auditConfig->ignoreAbandonedForAudit);
        $this->assertSame(['vendor/package2' => 'Block but do not report'], $auditConfig->ignoreAbandonedForBlocking);
    }

    public function testAuditFilteredWorstOfAcrossActiveLists(): void
    {
        $config = new Config();
        $config->merge([
            'config' => [
                'policy' => [
                    'malware' => ['audit' => 'ignore'],
                    'company-policy' => ['audit' => 'fail'],
                ],
            ],
        ]);

        $auditConfig = AuditConfig::fromPolicyConfig(PolicyConfig::fromConfig($config));

        $this->assertSame(Auditor::FILTERED_FAIL, $auditConfig->auditFiltered);
    }

    public function testAuditFilteredFallsBackToReportWhenNoFailingList(): void
    {
        $config = new Config();
        $config->merge([
            'config' => [
                'policy' => [
                    'malware' => ['audit' => 'ignore'],
                    'company-policy' => ['audit' => 'report'],
                ],
            ],
        ]);

        $auditConfig = AuditConfig::fromPolicyConfig(PolicyConfig::fromConfig($config));

        $this->assertSame(Auditor::FILTERED_REPORT, $auditConfig->auditFiltered);
    }

    public function testAuditFilteredIgnoreWhenAllListsIgnore(): void
    {
        $config = new Config();
        $config->merge([
            'config' => [
                'policy' => [
                    'malware' => ['audit' => 'ignore'],
                ],
            ],
        ]);

        $auditConfig = AuditConfig::fromPolicyConfig(PolicyConfig::fromConfig($config));

        $this->assertSame(Auditor::FILTERED_IGNORE, $auditConfig->auditFiltered);
    }

    public function testInvalidApplyValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid 'apply' value for 'CVE-2024-1234': invalid. Expected 'audit', 'block', or 'all'.");

        $config = new Config();
        $config->merge([
            'config' => [
                'audit' => [
                    'ignore' => [
                        'CVE-2024-1234' => [
                            'apply' => 'invalid',
                        ],
                    ],
                ],
            ],
        ]);

        AuditConfig::fromConfig($config);
    }

    public function testFromPolicyConfigPreservesMultiRuleReasons(): void
    {
        $config = new Config();
        $config->merge([
            'config' => [
                'policy' => [
                    'advisories' => [
                        'ignore' => [
                            'vendor/multi' => [
                                ['constraint' => '^1.0', 'reason' => 'v1 patched'],
                                ['constraint' => '^2.0', 'reason' => 'v2 mitigated'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $auditConfig = AuditConfig::fromPolicyConfig(PolicyConfig::fromConfig($config));

        self::assertArrayHasKey('vendor/multi', $auditConfig->ignoreListForAudit);
        $reason = $auditConfig->ignoreListForAudit['vendor/multi'];
        self::assertNotNull($reason, 'Reason must not be silently dropped');
        self::assertStringContainsString('v1 patched', $reason);
        self::assertStringContainsString('v2 mitigated', $reason);

        self::assertArrayHasKey('vendor/multi', $auditConfig->ignoreListForBlocking);
        $blockReason = $auditConfig->ignoreListForBlocking['vendor/multi'];
        self::assertNotNull($blockReason);
        self::assertStringContainsString('v1 patched', $blockReason);
        self::assertStringContainsString('v2 mitigated', $blockReason);
    }

    public function testFromPolicyConfigMultiRulePrefersConcreteReasonOverNull(): void
    {
        $config = new Config();
        $config->merge([
            'config' => [
                'policy' => [
                    'advisories' => [
                        'ignore' => [
                            'vendor/mixed' => [
                                ['constraint' => '^1.0'],
                                ['constraint' => '^2.0', 'reason' => 'v2 mitigated'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $auditConfig = AuditConfig::fromPolicyConfig(PolicyConfig::fromConfig($config));

        self::assertSame('v2 mitigated', $auditConfig->ignoreListForAudit['vendor/mixed']);
    }

    public function testFromPolicyConfigPreservesMultiRuleReasonsForAbandoned(): void
    {
        $config = new Config();
        $config->merge([
            'config' => [
                'policy' => [
                    'abandoned' => [
                        'ignore' => [
                            'vendor/multi-abandoned' => [
                                ['constraint' => '^1.0', 'reason' => 'fork ready'],
                                ['constraint' => '^2.0', 'reason' => 'maintained downstream'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $auditConfig = AuditConfig::fromPolicyConfig(PolicyConfig::fromConfig($config));

        $reason = $auditConfig->ignoreAbandonedForAudit['vendor/multi-abandoned'];
        self::assertNotNull($reason);
        self::assertStringContainsString('fork ready', $reason);
        self::assertStringContainsString('maintained downstream', $reason);
    }
}
