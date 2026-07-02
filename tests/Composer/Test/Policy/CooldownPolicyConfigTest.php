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

use Composer\Policy\CooldownPolicyConfig;
use Composer\Policy\IgnorePackageRule;
use Composer\Policy\ListPolicyConfig;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Semver\VersionParser;
use Composer\Test\TestCase;
use Composer\Util\Platform;

class CooldownPolicyConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        Platform::clearEnv('COMPOSER_POLICY_COOLDOWN_AGE');
    }

    public function testParseDurationNull(): void
    {
        self::assertNull(CooldownPolicyConfig::parseDuration(null));
        self::assertNull(CooldownPolicyConfig::parseDuration(''));
        self::assertNull(CooldownPolicyConfig::parseDuration(0));
        self::assertNull(CooldownPolicyConfig::parseDuration('0'));
    }

    public function testParseDurationInteger(): void
    {
        self::assertSame(3600, CooldownPolicyConfig::parseDuration(3600));
        self::assertSame(86400, CooldownPolicyConfig::parseDuration('86400'));
    }

    public function testParseDurationHumanReadable(): void
    {
        self::assertSame(90, CooldownPolicyConfig::parseDuration('90 seconds'));
        self::assertSame(60, CooldownPolicyConfig::parseDuration('1 minute'));
        self::assertSame(1800, CooldownPolicyConfig::parseDuration('30 minutes'));
        self::assertSame(3600, CooldownPolicyConfig::parseDuration('1 hour'));
        self::assertSame(86400, CooldownPolicyConfig::parseDuration('1 day'));
        self::assertSame(604800, CooldownPolicyConfig::parseDuration('7 days'));
        self::assertSame(604800, CooldownPolicyConfig::parseDuration('1 week'));
        self::assertSame(1209600, CooldownPolicyConfig::parseDuration('2 weeks'));
        // unit matching is case-insensitive and tolerant of surrounding/no whitespace
        self::assertSame(86400, CooldownPolicyConfig::parseDuration('1 DAY'));
        self::assertSame(604800, CooldownPolicyConfig::parseDuration('1week'));
        self::assertSame(7200, CooldownPolicyConfig::parseDuration('  2 hours  '));
    }

    public function testParseDurationInvalidFormatThrows(): void
    {
        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('Invalid policy.cooldown.age format');
        CooldownPolicyConfig::parseDuration('not a duration');
    }

    /**
     * Relative phrases and unsupported units must throw rather than silently
     * resolving to a surprising (or zero, i.e. disabled) cooldown.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function invalidFormatProvider(): iterable
    {
        yield 'relative tomorrow' => ['tomorrow'];
        yield 'relative next week' => ['next week'];
        yield 'relative next month' => ['next month'];
        yield 'relative today' => ['today'];
        yield 'relative midnight' => ['midnight'];
        yield 'unsupported unit month' => ['1 month'];
        yield 'unsupported unit year' => ['1 year'];
        yield 'past relative' => ['2 days ago'];
        yield 'trailing words' => ['7 days extra'];
        yield 'fractional seconds' => ['1.5'];
    }

    /**
     * @dataProvider invalidFormatProvider
     */
    public function testParseDurationInvalidFormatProviderThrows(string $duration): void
    {
        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('Invalid policy.cooldown.age format');
        CooldownPolicyConfig::parseDuration($duration);
    }

    /**
     * @return iterable<string, array{0: int|string}>
     */
    public static function negativeDurationProvider(): iterable
    {
        yield 'negative int' => [-3600];
        yield 'negative numeric string' => ['-3600'];
    }

    /**
     * @dataProvider negativeDurationProvider
     * @param int|string $duration
     */
    public function testParseDurationNegativeThrows($duration): void
    {
        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('cannot be negative');
        CooldownPolicyConfig::parseDuration($duration);
    }

    /**
     * @return iterable<array<mixed>>
     */
    public static function disabledProvider(): iterable
    {
        yield 'no cooldown key' => [[]];
        yield 'cooldown false' => [['cooldown' => false]];
        yield 'cooldown empty' => [['cooldown' => []]];
        yield 'cooldown true' => [['cooldown' => true]];
    }

    /**
     * @dataProvider disabledProvider
     * @param array<mixed> $policyConfig
     */
    public function testDefaultIsInactiveWithoutAge(array $policyConfig): void
    {
        $cooldown = CooldownPolicyConfig::fromRawConfig($policyConfig, new VersionParser());

        self::assertNull($cooldown->age);
        self::assertFalse($cooldown->hasCooldown());
        // block defaults to true, but with no age configured the policy is inert
        self::assertFalse($cooldown->shouldBlock(ListPolicyConfig::BLOCK_SCOPE_UPDATE));
    }

    public function testFromRawConfig(): void
    {
        $rawConfig = [
            'cooldown' => [
                'age' => '7 days',
                'block' => true,
                'audit' => 'report',
                'ignore' => [
                    'acme/pkg' => 'pinned by us',
                    'acme/pkg2' => ['constraint' => '1.0'],
                ],
            ],
        ];

        $this->assertEquals(
            new CooldownPolicyConfig(true, ListPolicyConfig::AUDIT_REPORT, [
                'acme/pkg' => [new IgnorePackageRule('acme/pkg', new MatchAllConstraint(), 'pinned by us')],
                'acme/pkg2' => [new IgnorePackageRule('acme/pkg2', (new VersionParser())->parseConstraints('1.0'))],
            ], 604800),
            CooldownPolicyConfig::fromRawConfig($rawConfig, new VersionParser())
        );
    }

    public function testIntegerAge(): void
    {
        $cooldown = CooldownPolicyConfig::fromRawConfig(['cooldown' => ['age' => 3600]], new VersionParser());

        self::assertSame(3600, $cooldown->age);
        self::assertTrue($cooldown->hasCooldown());
    }

    public function testShouldBlock(): void
    {
        $active = new CooldownPolicyConfig(true, ListPolicyConfig::AUDIT_IGNORE, [], 604800);
        self::assertTrue($active->shouldBlock(ListPolicyConfig::BLOCK_SCOPE_UPDATE));
        // cooldown is update/require-only — never blocks at install scope
        self::assertFalse($active->shouldBlock(ListPolicyConfig::BLOCK_SCOPE_INSTALL));

        $blockOff = new CooldownPolicyConfig(false, ListPolicyConfig::AUDIT_IGNORE, [], 604800);
        self::assertFalse($blockOff->shouldBlock(ListPolicyConfig::BLOCK_SCOPE_UPDATE));

        $noAge = new CooldownPolicyConfig(true, ListPolicyConfig::AUDIT_IGNORE, [], null);
        self::assertFalse($noAge->shouldBlock(ListPolicyConfig::BLOCK_SCOPE_UPDATE));
    }

    public function testEnvOverridesAge(): void
    {
        Platform::putEnv('COMPOSER_POLICY_COOLDOWN_AGE', '2 days');

        $cooldown = CooldownPolicyConfig::fromRawConfig([
            'cooldown' => ['age' => '7 days', 'ignore' => ['acme/pkg' => 'reason']],
        ], new VersionParser());

        self::assertSame(172800, $cooldown->age);
        // env overrides the duration but preserves the configured ignore rules
        self::assertArrayHasKey('acme/pkg', $cooldown->ignore);
    }

    public function testEnvDisablesCooldown(): void
    {
        Platform::putEnv('COMPOSER_POLICY_COOLDOWN_AGE', '0');

        $cooldown = CooldownPolicyConfig::fromRawConfig([
            'cooldown' => ['age' => '7 days'],
        ], new VersionParser());

        self::assertNull($cooldown->age);
        self::assertFalse($cooldown->hasCooldown());
    }

    public function testInvalidEnvValueThrowsWithEnvName(): void
    {
        Platform::putEnv('COMPOSER_POLICY_COOLDOWN_AGE', 'not a duration');

        // the error must name the env var the user set, not the config key
        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('Invalid value for COMPOSER_POLICY_COOLDOWN_AGE');
        CooldownPolicyConfig::fromRawConfig([], new VersionParser());
    }

    public function testWithBlockingDisabled(): void
    {
        $cooldown = new CooldownPolicyConfig(true, ListPolicyConfig::AUDIT_IGNORE, [], 604800);
        $disabled = $cooldown->withBlockingDisabled();

        self::assertFalse($disabled->block);
        self::assertSame(604800, $disabled->age);
    }
}
