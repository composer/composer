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

use Composer\Policy\AdvisoriesPolicyConfig;
use Composer\Policy\IgnoreIdRule;
use Composer\Policy\IgnorePackageRule;
use Composer\Policy\IgnoreSeverityRule;
use Composer\Policy\ListPolicyConfig;
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
}
