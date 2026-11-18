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

use Composer\Policy\IgnoreSeverityRule;
use Composer\Test\TestCase;

class IgnoreSeverityRuleTest extends TestCase
{
    public function testParseIgnoreSeverityMapWithEmptyConfig(): void
    {
        $rules = IgnoreSeverityRule::parseIgnoreSeverityMap([]);

        self::assertSame([], $rules);
    }

    public function testParseIgnoreSeverityMapWithIntegerKeyAndStringValue(): void
    {
        $rules = IgnoreSeverityRule::parseIgnoreSeverityMap(['low', 'medium']);

        self::assertArrayHasKey('low', $rules);
        self::assertSame('low', $rules['low']->severity);
        self::assertNull($rules['low']->reason);
        self::assertTrue($rules['low']->onBlock);
        self::assertTrue($rules['low']->onAudit);

        self::assertArrayHasKey('medium', $rules);
        self::assertSame('medium', $rules['medium']->severity);
        self::assertNull($rules['medium']->reason);
        self::assertTrue($rules['medium']->onBlock);
        self::assertTrue($rules['medium']->onAudit);
    }

    public function testParseIgnoreSeverityMapWithMultipleMixedEntries(): void
    {
        $rules = IgnoreSeverityRule::parseIgnoreSeverityMap([
            'low' => 'reason',
            'medium' => ['on-block' => false, 'reason' => 'other reason'],
            'high' => null,
            'critical' => ['on-audit' => false],
        ]);

        self::assertCount(4, $rules);

        self::assertSame('low', $rules['low']->severity);
        self::assertSame('reason', $rules['low']->reason);
        self::assertTrue($rules['low']->onBlock);
        self::assertTrue($rules['low']->onAudit);

        self::assertSame('medium', $rules['medium']->severity);
        self::assertSame('other reason', $rules['medium']->reason);
        self::assertFalse($rules['medium']->onBlock);
        self::assertTrue($rules['medium']->onAudit);

        self::assertSame('high', $rules['high']->severity);
        self::assertNull($rules['high']->reason);
        self::assertTrue($rules['high']->onBlock);
        self::assertTrue($rules['high']->onAudit);

        self::assertSame('critical', $rules['critical']->severity);
        self::assertNull($rules['critical']->reason);
        self::assertTrue($rules['critical']->onBlock);
        self::assertFalse($rules['critical']->onAudit);
    }

    /**
     * @return array<string, array{0: array<mixed>}>
     */
    public function provideUnsupportedIgnoreSeverityMapShapes(): array
    {
        return [
            'integer key with null value' => [[null]],
            'integer key with array value' => [[['severity' => 'low']]],
            'integer key with bool value' => [[true]],
            'integer key with integer value' => [[42]],
            'string key with bool value' => [['low' => true]],
            'string key with integer value' => [['low' => 42]],
        ];
    }

    /**
     * @dataProvider provideUnsupportedIgnoreSeverityMapShapes
     * @param array<mixed> $config
     */
    public function testParseIgnoreSeverityMapRejectsUnsupportedShapes(array $config): void
    {
        self::expectException(\UnexpectedValueException::class);
        IgnoreSeverityRule::parseIgnoreSeverityMap($config);
    }
}
