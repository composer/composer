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

use Composer\Policy\IgnoreIdRule;
use Composer\Test\TestCase;

class IgnoreIdRuleTest extends TestCase
{
    public function testParseIgnoreIdMapWithEmptyConfig(): void
    {
        $rules = IgnoreIdRule::parseIgnoreIdMap([]);

        self::assertSame([], $rules);
    }

    public function testParseIgnoreIdMapWithIntegerKeyAndStringValue(): void
    {
        $rules = IgnoreIdRule::parseIgnoreIdMap(['CVE-123', 'GHSA-456']);

        self::assertArrayHasKey('CVE-123', $rules);
        self::assertSame('CVE-123', $rules['CVE-123']->id);
        self::assertNull($rules['CVE-123']->reason);
        self::assertTrue($rules['CVE-123']->onBlock);
        self::assertTrue($rules['CVE-123']->onAudit);

        self::assertArrayHasKey('GHSA-456', $rules);
        self::assertSame('GHSA-456', $rules['GHSA-456']->id);
        self::assertNull($rules['GHSA-456']->reason);
        self::assertTrue($rules['GHSA-456']->onBlock);
        self::assertTrue($rules['GHSA-456']->onAudit);
    }

    public function testParseIgnoreIdMapWithMultipleMixedEntries(): void
    {
        $rules = IgnoreIdRule::parseIgnoreIdMap([
            'CVE-123' => 'reason',
            'CVE-456' => ['on-block' => false, 'reason' => 'other reason'],
            'CVE-789' => null,
            'CVE-012' => ['on-audit' => false],
        ]);

        self::assertCount(4, $rules);

        self::assertSame('reason', $rules['CVE-123']->reason);
        self::assertSame('CVE-123', $rules['CVE-123']->id);
        self::assertTrue($rules['CVE-123']->onBlock);
        self::assertTrue($rules['CVE-123']->onAudit);

        self::assertSame('other reason', $rules['CVE-456']->reason);
        self::assertSame('CVE-456', $rules['CVE-456']->id);
        self::assertFalse($rules['CVE-456']->onBlock);
        self::assertTrue($rules['CVE-456']->onAudit);

        self::assertSame('CVE-789', $rules['CVE-789']->id);
        self::assertNull($rules['CVE-789']->reason);
        self::assertTrue($rules['CVE-789']->onBlock);
        self::assertTrue($rules['CVE-789']->onAudit);

        self::assertSame('CVE-012', $rules['CVE-012']->id);
        self::assertNull($rules['CVE-012']->reason);
        self::assertTrue($rules['CVE-012']->onBlock);
        self::assertFalse($rules['CVE-012']->onAudit);
    }

    /**
     * @return array<string, array{0: array<mixed>}>
     */
    public function provideUnsupportedIgnoreIdMapShapes(): array
    {
        return [
            'integer key with null value' => [[null]],
            'integer key with array value' => [[['id' => 'CVE-1']]],
            'integer key with bool value' => [[true]],
            'integer key with integer value' => [[42]],
            'string key with bool value' => [['CVE-1' => true]],
            'string key with integer value' => [['CVE-1' => 42]],
        ];
    }

    /**
     * @dataProvider provideUnsupportedIgnoreIdMapShapes
     * @param array<mixed> $config
     */
    public function testParseIgnoreIdMapRejectsUnsupportedShapes(array $config): void
    {
        self::expectException(\UnexpectedValueException::class);
        IgnoreIdRule::parseIgnoreIdMap($config);
    }
}
