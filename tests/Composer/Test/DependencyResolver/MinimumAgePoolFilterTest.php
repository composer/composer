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

use Composer\DependencyResolver\MinimumAgePoolFilter;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\Package\Package;
use Composer\Test\TestCase;

class MinimumAgePoolFilterTest extends TestCase
{
    public function testFiltersPackagesByReleaseDateWhileKeepingFixedAndLockedPackages(): void
    {
        $referenceTime = 1700000000;
        $oldPackage = new Package('acme/old', '1.0.0.0', '1.0.0');
        $oldPackage->setReleaseDate(new \DateTimeImmutable('@'.($referenceTime - 8 * 86400)));
        $boundaryPackage = new Package('acme/boundary', '1.0.0.0', '1.0.0');
        $boundaryPackage->setReleaseDate(new \DateTimeImmutable('@'.($referenceTime - 7 * 86400)));
        $recentPackage = new Package('acme/recent', '1.0.0.0', '1.0.0');
        $recentPackage->setReleaseDate(new \DateTimeImmutable('@'.($referenceTime - 6 * 86400)));
        $undatedPackage = new Package('acme/undated', '1.0.0.0', '1.0.0');
        $fixedPackage = new Package('acme/fixed', '1.0.0.0', '1.0.0');
        $lockedPackage = new Package('acme/locked', '1.0.0.0', '1.0.0');
        $lockedAlias = self::getAliasPackage($lockedPackage, '1.0.x-dev');
        $rootPackage = self::getRootPackage();
        $platformPackage = new Package('php', '8.4.0.0', '8.4.0');

        $request = new Request();
        $request->fixPackage($fixedPackage);
        $request->lockPackage($lockedPackage);

        $filter = new MinimumAgePoolFilter(7, $referenceTime);
        $filteredPool = $filter->filter(new Pool([
            $oldPackage,
            $boundaryPackage,
            $recentPackage,
            $undatedPackage,
            $fixedPackage,
            $lockedPackage,
            $lockedAlias,
            $rootPackage,
            $platformPackage,
        ]), $request);

        self::assertSame([
            $oldPackage,
            $boundaryPackage,
            $fixedPackage,
            $lockedPackage,
            $lockedAlias,
            $rootPackage,
            $platformPackage,
        ], $filteredPool->getPackages());
    }

    public function testZeroMinimumAgeKeepsUndatedPackages(): void
    {
        $package = new Package('acme/undated', '1.0.0.0', '1.0.0');
        $filter = new MinimumAgePoolFilter(0);

        self::assertSame([$package], $filter->filter(new Pool([$package]), new Request())->getPackages());
    }
}
