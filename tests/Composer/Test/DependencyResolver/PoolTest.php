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

namespace Composer\Test\DependencyResolver;

use Composer\DependencyResolver\Pool;
use Composer\Semver\Constraint\Constraint;
use Composer\Test\TestCase;

class PoolTest extends TestCase
{
    public function testPool(): void
    {
        $package = self::getPackage('foo', '1');

        $pool = $this->createPool([$package]);

        self::assertEquals([$package], $pool->whatProvides('foo'));
        self::assertEquals([$package], $pool->whatProvides('foo'));
    }

    public function testWhatProvidesPackageWithConstraint(): void
    {
        $firstPackage = self::getPackage('foo', '1');
        $secondPackage = self::getPackage('foo', '2');

        $pool = $this->createPool([
            $firstPackage,
            $secondPackage,
        ]);

        self::assertEquals([$firstPackage, $secondPackage], $pool->whatProvides('foo'));
        self::assertEquals([$secondPackage], $pool->whatProvides('foo', self::getVersionConstraint('==', '2')));
    }

    public function testPackageById(): void
    {
        $package = self::getPackage('foo', '1');

        $pool = $this->createPool([$package]);

        self::assertSame($package, $pool->packageById(1));
    }

    public function testWhatProvidesWhenPackageCannotBeFound(): void
    {
        $pool = $this->createPool();

        self::assertEquals([], $pool->whatProvides('foo'));
    }

    public function testGetCooldownInfoPicksChronologicallyEarliestAcrossOffsets(): void
    {
        // The two release-date strings sort differently lexically than chronologically
        // because of their differing UTC offsets:
        //   1.0.0 => 2026-01-12T01:00:00+02:00  (instant 2026-01-11T23:00:00Z, earlier)
        //   2.0.0 => 2026-01-12T00:30:00+00:00  (instant 2026-01-12T00:30:00Z, later)
        // Lexically "2026-01-12T00:30..." < "2026-01-12T01:00...", so a string compare
        // would wrongly pick 2.0.0 as the soonest-available version.
        $cooldownRemovedVersions = [
            'vendor/pkg' => [
                '1.0.0.0' => [
                    'prettyVersion' => '1.0.0',
                    'releaseDate' => '2026-01-12T01:00:00+02:00',
                    'availableIn' => '5 days',
                    'source' => 'time',
                ],
                '2.0.0.0' => [
                    'prettyVersion' => '2.0.0',
                    'releaseDate' => '2026-01-12T00:30:00+00:00',
                    'availableIn' => '6 days',
                    'source' => 'time',
                ],
            ],
        ];

        $pool = new Pool([], [], [], [], [], [], [], $cooldownRemovedVersions);

        $info = $pool->getCooldownInfoForPackageVersion('vendor/pkg', new Constraint('>=', '1.0.0.0'));

        self::assertNotNull($info);
        self::assertSame('1.0.0', $info['prettyVersion']);
    }

    /**
     * @param array<\Composer\Package\BasePackage>|null $packages
     */
    protected function createPool(?array $packages = []): Pool
    {
        return new Pool($packages);
    }
}
