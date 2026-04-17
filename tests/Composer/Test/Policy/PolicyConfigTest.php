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
        Platform::clearEnv('COMPOSER_POLICY_ADVISORIES_BLOCK');
        Platform::clearEnv('COMPOSER_POLICY_MALWARE_BLOCK');
        Platform::clearEnv('COMPOSER_SECURITY_BLOCKING_ABANDONED');
        Platform::clearEnv('COMPOSER_AUDIT_ABANDONED');

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public function provideInvalidBlockAbandonedValues(): iterable
    {
        yield 'arbitrary string' => ['abc'];
        yield 'empty string' => [''];
        yield 'truthy non-numeric' => ['true'];
        yield 'integer above 1' => ['2'];
        yield 'integer below 0' => ['-1'];
    }

    /**
     * @dataProvider provideInvalidBlockAbandonedValues
     */
    public function testEnvBlockAbandonedRejectsInvalidValues(string $value): void
    {
        Platform::putEnv('COMPOSER_SECURITY_BLOCKING_ABANDONED', $value);

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('COMPOSER_SECURITY_BLOCKING_ABANDONED');

        PolicyConfig::fromConfig(new Config(true));
    }

    /**
     * @dataProvider provideInvalidBlockAbandonedValues
     */
    public function testEnvBlockAbandonedRejectsInvalidValuesWithoutUseEnvironment(string $value): void
    {
        // useEnvironment=false bypasses Config::get('audit')'s validation, so
        // PolicyConfig::fromConfig is the only line of defence here.
        Platform::putEnv('COMPOSER_SECURITY_BLOCKING_ABANDONED', $value);

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('COMPOSER_SECURITY_BLOCKING_ABANDONED');

        PolicyConfig::fromConfig(new Config(false));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public function provideReservedCustomListNames(): iterable
    {
        // Future-reserved prefix
        yield 'ignore-foo prefix' => ['ignore-foo'];
        yield 'ignoremalware prefix' => ['ignoremalware'];
        // Future-reserved exact names
        yield 'package' => ['package'];
        yield 'packages' => ['packages'];
        yield 'license' => ['license'];
        yield 'licence' => ['licence'];
        yield 'support' => ['support'];
        yield 'maintenance' => ['maintenance'];
        yield 'security' => ['security'];
        yield 'minimum-release-age' => ['minimum-release-age'];
    }

    /**
     * @dataProvider provideReservedCustomListNames
     */
    public function testRejectsReservedCustomListNames(string $listName): void
    {
        $config = new Config(false);
        $config->merge(['config' => ['policy' => [
            $listName => ['block' => true],
        ]]]);

        self::expectException(\UnexpectedValueException::class);
        self::expectExceptionMessage($listName);

        PolicyConfig::fromConfig($config);
    }

    public function testAllowsIgnoreUnreachableSiblingKey(): void
    {
        $config = new Config(false);
        $config->merge(['config' => ['policy' => [
            'ignore-unreachable' => true,
        ]]]);

        $policyConfig = PolicyConfig::fromConfig($config);

        self::assertTrue($policyConfig->ignoreUnreachable->update);
        self::assertSame([], $policyConfig->customLists);
    }

    public function testAllowsRegularCustomListName(): void
    {
        $config = new Config(false);
        $config->merge(['config' => ['policy' => [
            'company-policy' => ['block' => true],
        ]]]);

        $policyConfig = PolicyConfig::fromConfig($config);

        self::assertArrayHasKey('company-policy', $policyConfig->customLists);
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

    /**
     * @dataProvider provideInvalidBlockAbandonedValues
     */
    public function testEnvAdvisoriesBlockRejectsInvalidValues(string $value): void
    {
        Platform::putEnv('COMPOSER_POLICY_ADVISORIES_BLOCK', $value);

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('COMPOSER_POLICY_ADVISORIES_BLOCK');

        PolicyConfig::fromConfig(new Config(false));
    }

    /**
     * @dataProvider provideInvalidBlockAbandonedValues
     */
    public function testEnvMalwareBlockRejectsInvalidValuesAtParsedLayer(string $value): void
    {
        Platform::putEnv('COMPOSER_POLICY_MALWARE_BLOCK', $value);

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('COMPOSER_POLICY_MALWARE_BLOCK');

        PolicyConfig::fromConfig(new Config(false));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public function provideInvalidAuditAbandonedValues(): iterable
    {
        yield 'arbitrary string' => ['abc'];
        yield 'empty string' => [''];
        yield 'numeric' => ['1'];
        yield 'wrong case' => ['Fail'];
    }

    /**
     * @dataProvider provideInvalidAuditAbandonedValues
     */
    public function testEnvAuditAbandonedRejectsInvalidValues(string $value): void
    {
        Platform::putEnv('COMPOSER_AUDIT_ABANDONED', $value);

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('COMPOSER_AUDIT_ABANDONED');

        PolicyConfig::fromConfig(new Config(false));
    }
}
