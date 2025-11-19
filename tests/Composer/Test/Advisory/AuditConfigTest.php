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

        $this->assertSame(['CVE-2024-1234', 'CVE-2024-5678'], $auditConfig->ignoreListForAudit);
        $this->assertSame(['CVE-2024-1234', 'CVE-2024-5678'], $auditConfig->ignoreListForBlocking);
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

        $this->assertSame(['CVE-2024-1234'], $auditConfig->ignoreListForAudit);
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
        $this->assertSame(['CVE-2024-1234'], $auditConfig->ignoreListForBlocking);
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
            'CVE-2024-1234',
            'CVE-2024-5678',
            'CVE-2024-9999',
        ], $auditConfig->ignoreListForAudit);
        $this->assertSame([
            'CVE-2024-1234',
            'CVE-2024-5678',
            'CVE-2024-8888',
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

        $this->assertSame(['low', 'medium'], $auditConfig->ignoreSeverityForAudit);
        $this->assertSame(['low', 'medium'], $auditConfig->ignoreSeverityForBlocking);
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

        $this->assertSame(['low'], $auditConfig->ignoreSeverityForAudit);
        $this->assertSame(['medium'], $auditConfig->ignoreSeverityForBlocking);
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

        $this->assertSame(['vendor/package1', 'vendor/package2'], $auditConfig->ignoreAbandonedForAudit);
        $this->assertSame(['vendor/package1', 'vendor/package2'], $auditConfig->ignoreAbandonedForBlocking);
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

        $this->assertSame(['vendor/package1'], $auditConfig->ignoreAbandonedForAudit);
        $this->assertSame(['vendor/package2'], $auditConfig->ignoreAbandonedForBlocking);
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
}
