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

namespace Composer\Test\FilterList;

use Composer\FilterList\FilterListEntryBuilder;
use Composer\Semver\Constraint\Constraint;
use Composer\Test\TestCase;

class FilterListEntryBuilderTest extends TestCase
{
    public function testBuildFiltersByConstraintMatch(): void
    {
        $builder = new FilterListEntryBuilder();

        $rawByList = [
            'malware' => [
                ['package' => 'vendor/foo', 'constraint' => '^1.0', 'url' => 'https://example.org/foo', 'reason' => 'bad', 'id' => 'A'],
                ['package' => 'vendor/foo', 'constraint' => '^9.0', 'url' => null, 'reason' => 'ignored', 'id' => 'B'],
                ['package' => 'vendor/unknown', 'constraint' => '*', 'url' => null, 'reason' => 'ignored', 'id' => 'C'],
            ],
        ];

        $result = $builder->build($rawByList, [
            'vendor/foo' => new Constraint('=', '1.2.3.0'),
        ]);

        self::assertArrayHasKey('malware', $result);
        self::assertCount(1, $result['malware']);
        self::assertSame('vendor/foo', $result['malware'][0]->packageName);
        self::assertSame('A', $result['malware'][0]->id);
    }

    public function testBuildFillsInDefaultPackageWhenMissing(): void
    {
        $builder = new FilterListEntryBuilder();

        // Per-package metadata files omit the "package" field — the builder uses defaultPackage.
        $rawByList = [
            'malware' => [
                ['constraint' => '^1.0', 'url' => 'https://example.org/foo', 'reason' => 'bad', 'id' => 'PKFE-001'],
            ],
        ];

        $result = $builder->build(
            $rawByList,
            ['vendor/foo' => new Constraint('=', '1.2.3.0')],
            'vendor/foo'
        );

        self::assertCount(1, $result['malware']);
        self::assertSame('vendor/foo', $result['malware'][0]->packageName);
    }

    public function testBuildPrefersExplicitPackageOverDefault(): void
    {
        $builder = new FilterListEntryBuilder();

        $rawByList = [
            'malware' => [
                ['package' => 'vendor/foo', 'constraint' => '*', 'id' => 'X'],
            ],
        ];

        // defaultPackage points at a different package; the explicit package field must win.
        $result = $builder->build(
            $rawByList,
            ['vendor/foo' => new Constraint('=', '1.0.0.0')],
            'vendor/bar'
        );

        self::assertSame('vendor/foo', $result['malware'][0]->packageName);
    }

    public function testBuildIgnoresMalformedListShapes(): void
    {
        $builder = new FilterListEntryBuilder();

        $rawByList = [
            'malware' => 'not-an-array',
            5 => [['package' => 'vendor/foo', 'constraint' => '*']],
            'typosquatting' => [
                'not-an-entry',
                ['package' => 'vendor/foo', 'constraint' => '*'],
            ],
        ];

        $result = $builder->build($rawByList, [
            'vendor/foo' => new Constraint('=', '1.0.0.0'),
        ]);

        self::assertSame(['typosquatting'], array_keys($result));
        self::assertCount(1, $result['typosquatting']);
    }

    public function testBuildReturnsEmptyForEmptyInput(): void
    {
        $builder = new FilterListEntryBuilder();

        self::assertSame([], $builder->build([], ['vendor/foo' => new Constraint('=', '1.0.0.0')]));
    }
}
