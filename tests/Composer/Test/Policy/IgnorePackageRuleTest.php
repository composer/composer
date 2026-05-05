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

use Composer\Policy\IgnorePackageRule;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Test\TestCase;

class IgnorePackageRuleTest extends TestCase
{
    public function testParseIgnoreMapWithEmptyConfig(): void
    {
        $rules = IgnorePackageRule::parseIgnoreMap([]);

        self::assertSame([], $rules);
    }

    public function testParseIgnoreMapWithIntegerKeyAndStringValue(): void
    {
        $rules = IgnorePackageRule::parseIgnoreMap(['vendor/foo', 'vendor/bar']);

        self::assertArrayHasKey('vendor/foo', $rules);
        self::assertCount(1, $rules['vendor/foo']);
        self::assertSame('vendor/foo', $rules['vendor/foo'][0]->packageName);
        self::assertInstanceOf(MatchAllConstraint::class, $rules['vendor/foo'][0]->constraint);
        self::assertNull($rules['vendor/foo'][0]->reason);
        self::assertTrue($rules['vendor/foo'][0]->onBlock);
        self::assertTrue($rules['vendor/foo'][0]->onAudit);

        self::assertArrayHasKey('vendor/bar', $rules);
        self::assertCount(1, $rules['vendor/bar']);
        self::assertSame('vendor/bar', $rules['vendor/bar'][0]->packageName);
        self::assertInstanceOf(MatchAllConstraint::class, $rules['vendor/bar'][0]->constraint);
        self::assertNull($rules['vendor/bar'][0]->reason);
        self::assertTrue($rules['vendor/bar'][0]->onBlock);
        self::assertTrue($rules['vendor/bar'][0]->onAudit);
    }

    public function testParseIgnoreMapWithMultipleMixedEntries(): void
    {
        $rules = IgnorePackageRule::parseIgnoreMap([
            'vendor/foo' => 'reason',
            'vendor/bar' => ['constraint' => '^2.0', 'on-block' => false, 'reason' => 'other reason'],
            'vendor/baz' => null,
            'vendor/qux' => ['on-audit' => false],
        ]);

        self::assertCount(4, $rules);

        self::assertSame('vendor/foo', $rules['vendor/foo'][0]->packageName);
        self::assertInstanceOf(MatchAllConstraint::class, $rules['vendor/foo'][0]->constraint);
        self::assertSame('reason', $rules['vendor/foo'][0]->reason);
        self::assertTrue($rules['vendor/foo'][0]->onBlock);
        self::assertTrue($rules['vendor/foo'][0]->onAudit);

        self::assertSame('vendor/bar', $rules['vendor/bar'][0]->packageName);
        self::assertSame('^2.0', $rules['vendor/bar'][0]->constraint->getPrettyString());
        self::assertSame('other reason', $rules['vendor/bar'][0]->reason);
        self::assertFalse($rules['vendor/bar'][0]->onBlock);
        self::assertTrue($rules['vendor/bar'][0]->onAudit);

        self::assertSame('vendor/baz', $rules['vendor/baz'][0]->packageName);
        self::assertInstanceOf(MatchAllConstraint::class, $rules['vendor/baz'][0]->constraint);
        self::assertNull($rules['vendor/baz'][0]->reason);
        self::assertTrue($rules['vendor/baz'][0]->onBlock);
        self::assertTrue($rules['vendor/baz'][0]->onAudit);

        self::assertSame('vendor/qux', $rules['vendor/qux'][0]->packageName);
        self::assertInstanceOf(MatchAllConstraint::class, $rules['vendor/qux'][0]->constraint);
        self::assertNull($rules['vendor/qux'][0]->reason);
        self::assertTrue($rules['vendor/qux'][0]->onBlock);
        self::assertFalse($rules['vendor/qux'][0]->onAudit);
    }

    public function testParseIgnoreMapWithArrayOfRuleObjects(): void
    {
        $rules = IgnorePackageRule::parseIgnoreMap([
            'vendor/foo' => [
                ['constraint' => '^1.0', 'reason' => 'old version'],
                ['constraint' => '^3.0', 'on-block' => false],
            ],
        ]);

        self::assertArrayHasKey('vendor/foo', $rules);
        self::assertCount(2, $rules['vendor/foo']);

        self::assertSame('vendor/foo', $rules['vendor/foo'][0]->packageName);
        self::assertSame('^1.0', $rules['vendor/foo'][0]->constraint->getPrettyString());
        self::assertSame('old version', $rules['vendor/foo'][0]->reason);
        self::assertTrue($rules['vendor/foo'][0]->onBlock);
        self::assertTrue($rules['vendor/foo'][0]->onAudit);

        self::assertSame('vendor/foo', $rules['vendor/foo'][1]->packageName);
        self::assertSame('^3.0', $rules['vendor/foo'][1]->constraint->getPrettyString());
        self::assertNull($rules['vendor/foo'][1]->reason);
        self::assertFalse($rules['vendor/foo'][1]->onBlock);
        self::assertTrue($rules['vendor/foo'][1]->onAudit);
    }

    /**
     * @return array<string, array{0: array<mixed>}>
     */
    public function provideUnsupportedIgnoreMapShapes(): array
    {
        return [
            'integer key with array value' => [[
                ['package' => 'vendor/foo', 'constraint' => '^1.0'],
            ]],
            'integer key with bool value' => [[true]],
            'string key with bool value' => [['vendor/foo' => true]],
            'integer key with integer value' => [[42]],
        ];
    }

    /**
     * @dataProvider provideUnsupportedIgnoreMapShapes
     * @param array<mixed> $config
     */
    public function testParseIgnoreMapRejectsUnsupportedShapes(array $config): void
    {
        self::expectException(\UnexpectedValueException::class);
        IgnorePackageRule::parseIgnoreMap($config);
    }
}
