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
use Composer\Policy\ListPolicyConfig;
use Composer\Policy\PolicyConfig;
use Composer\Test\TestCase;
use Composer\Util\Platform;

class PolicyConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        Platform::clearEnv('COMPOSER_POLICY_ADVISORIES_BLOCK');
        Platform::clearEnv('COMPOSER_POLICY_MALWARE_BLOCK');
        Platform::clearEnv('COMPOSER_SECURITY_BLOCKING_ABANDONED');
        Platform::clearEnv('COMPOSER_AUDIT_ABANDONED');
    }

    /**
     * @return iterable<array{string, bool, bool}>
     */
    public static function advisoriesBlockProvider(): iterable
    {
        yield 'enable' => ['1', false, true];
        yield 'disable' => ['0', true, false];
    }

    /**
     * @dataProvider advisoriesBlockProvider
     */
    public function testComposerPolicyAdvisoriesBlock(string $envVar, bool $blockConfig, bool $expected): void
    {
        Platform::putEnv('COMPOSER_POLICY_ADVISORIES_BLOCK', $envVar);

        $config = new Config();
        $config->merge(['config' => ['policy' => ['advisories' => ['block' => $blockConfig, 'audit' => 'report']]]]);
        $policyConfig = PolicyConfig::fromConfig($config);

        $this->assertSame($expected, $policyConfig->advisories->block);
        $this->assertSame(ListPolicyConfig::AUDIT_REPORT, $policyConfig->advisories->audit);
    }

    /**
     * @return iterable<array{string, bool, bool}>
     */
    public static function abandonedBlockProvider(): iterable
    {
        yield 'enable' => ['1', false, true];
        yield 'disable' => ['0', true, false];
    }

    /**
     * @dataProvider abandonedBlockProvider
     */
    public function testComposerSecurityBlockingAbandoned(string $envVar, bool $blockConfig, bool $expected): void
    {
        Platform::putEnv('COMPOSER_SECURITY_BLOCKING_ABANDONED', $envVar);

        $config = new Config();
        $config->merge(['config' => ['policy' => ['abandoned' => ['block' => $blockConfig, 'audit' => 'report']]]]);
        $policyConfig = PolicyConfig::fromConfig($config);

        $this->assertSame($expected, $policyConfig->abandoned->block);
        $this->assertSame(ListPolicyConfig::AUDIT_REPORT, $policyConfig->abandoned->audit);
    }

    /**
     * @dataProvider abandonedBlockProvider
     */
    public function testComposerSecurityBlockingAbandonedWithAuditConfig(string $envVar, bool $blockConfig, bool $expected): void
    {
        Platform::putEnv('COMPOSER_SECURITY_BLOCKING_ABANDONED', $envVar);

        $config = new Config();
        $config->merge(['config' => ['audit' => ['block-abandoned' => $blockConfig]]]);
        $policyConfig = PolicyConfig::fromConfig($config);

        $this->assertSame($expected, $policyConfig->abandoned->block);
    }

    /**
     * @return iterable<array{string, string, string}>
     */
    public static function abandonedAuditProvider(): iterable
    {
        yield 'report' => ['report', 'fail', 'report'];
        yield 'fail' => ['fail', 'report', 'fail'];
    }

    /**
     * @dataProvider abandonedAuditProvider
     */
    public function testComposerAuditAbandonedSetsAuditMode(string $envVar, string $auditConfig, string $expected): void
    {
        Platform::putEnv('COMPOSER_AUDIT_ABANDONED', $envVar);

        $config = new Config();
        $config->merge(['config' => ['policy' => ['abandoned' => ['audit' => $auditConfig]]]]);
        $policyConfig = PolicyConfig::fromConfig($config);

        $this->assertSame($expected, $policyConfig->abandoned->audit);
    }

    /**
     * @dataProvider abandonedAuditProvider
     */
    public function testComposerAuditAbandonedSetsAuditModeWithAudtConfig(string $envVar, string $auditConfig, string $expected): void
    {
        Platform::putEnv('COMPOSER_AUDIT_ABANDONED', $envVar);

        $config = new Config();
        $config->merge(['config' => ['audit' => ['abandoned' => $auditConfig]]]);
        $policyConfig = PolicyConfig::fromConfig($config);

        $this->assertSame($expected, $policyConfig->abandoned->audit);
    }

    /**
     * @return iterable<array{string, bool, bool}>
     */
    public static function malwareBlockProvider(): iterable
    {
        yield 'enable' => ['1', false, true];
        yield 'disable' => ['0', true, false];
    }

    /**
     * @dataProvider malwareBlockProvider
     */
    public function testComposerPolicyMalwareBlock(string $envVar, bool $blockConfig, bool $expected): void
    {
        Platform::putEnv('COMPOSER_POLICY_MALWARE_BLOCK', $envVar);

        $config = new Config();
        $config->merge(['config' => ['policy' => ['malware' => ['block' => $blockConfig, 'audit' => 'report']]]]);
        $policyConfig = PolicyConfig::fromConfig($config);

        $this->assertSame($expected, $policyConfig->malware->block);
        $this->assertSame(ListPolicyConfig::AUDIT_REPORT, $policyConfig->malware->audit);
    }

    public function testBothAbandonedEnvVarsApplyIndependently(): void
    {
        Platform::putEnv('COMPOSER_SECURITY_BLOCKING_ABANDONED', '1');
        Platform::putEnv('COMPOSER_AUDIT_ABANDONED', 'report');

        $config = new Config();
        $policyConfig = PolicyConfig::fromConfig($config);

        $this->assertTrue($policyConfig->abandoned->block);
        $this->assertSame(ListPolicyConfig::AUDIT_REPORT, $policyConfig->abandoned->audit);
    }
}
