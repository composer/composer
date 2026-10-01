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

use Composer\DependencyResolver\PolicyRemovalReason;
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

    public function testGetPolicyRemovedVersionsFiltersByConstraintInVersionOrder(): void
    {
        $pool = new Pool([], [], [], [], [
            'vendor/pkg' => [
                '2.0.0.0' => $v2 = PolicyRemovalReason::abandoned('vendor/pkg', '2.0.0'),
                '1.0.0.0' => $v1 = PolicyRemovalReason::abandoned('vendor/pkg', '1.0.0'),
                '3.0.0.0' => PolicyRemovalReason::abandoned('vendor/pkg', '3.0.0'),
            ],
        ]);

        self::assertSame(['1.0.0.0' => $v1, '2.0.0.0' => $v2], $pool->getPolicyRemovedVersions('vendor/pkg', new Constraint('<', '3.0.0.0')));
        self::assertCount(3, $pool->getPolicyRemovedVersions('vendor/pkg'));
        self::assertSame([], $pool->getPolicyRemovedVersions('vendor/other'));
        self::assertSame($v2, $pool->getPolicyRemovalReason('vendor/pkg', '2.0.0.0'));
        self::assertNull($pool->getPolicyRemovalReason('vendor/pkg', '4.0.0.0'));
    }

    /**
     * @param array<\Composer\Package\BasePackage>|null $packages
     */
    protected function createPool(?array $packages = []): Pool
    {
        return new Pool($packages);
    }
}
