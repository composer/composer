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
use Composer\Policy\PolicyConfig;
use Composer\Test\TestCase;
use Composer\Util\Platform;

class PolicyConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        Platform::clearEnv('COMPOSER_SECURITY_BLOCKING_ABANDONED');
        parent::tearDown();
    }

    public function testEnvBlockAbandonedTrue(): void
    {
        Platform::putEnv('COMPOSER_SECURITY_BLOCKING_ABANDONED', '1');

        $policyConfig = PolicyConfig::fromConfig(new Config(true));

        self::assertTrue($policyConfig->abandoned->block);
    }

    public function testEnvBlockAbandonedFalse(): void
    {
        Platform::putEnv('COMPOSER_SECURITY_BLOCKING_ABANDONED', '0');

        $policyConfig = PolicyConfig::fromConfig(new Config(true));

        self::assertFalse($policyConfig->abandoned->block);
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
     * @return iterable<string, array{0: string}>
     */
    public function provideReservedBuiltInListNames(): iterable
    {
        foreach (PolicyConfig::RESERVED_NAMES as $name) {
            yield $name => [$name];
        }
    }

    /**
     * @dataProvider provideReservedBuiltInListNames
     */
    public function testAssertCustomListNameAllowedRejectsReservedBuiltInNames(string $listName): void
    {
        // Defence-in-depth: under fromConfig the built-in skip prevents these names
        // from ever reaching the assert, but the assert itself must still reject them
        // so future refactors of the loop cannot silently accept a reserved name as a
        // custom list. We exercise the private method directly via reflection.
        $method = new \ReflectionMethod(PolicyConfig::class, 'assertCustomListNameAllowed');
        $method->setAccessible(true);

        self::expectException(\UnexpectedValueException::class);
        self::expectExceptionMessage($listName);

        $method->invoke(null, $listName);
    }
}
