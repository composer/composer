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

namespace Composer\DependencyResolver;

use Composer\FilterList\FilterListEntry;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Repository\FilterListProviderInterface;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MatchAllConstraint;

/**
 * @internal
 */
class FilterListPoolFilter
{
    /**
     * @param array<RepositoryInterface> $repositories
     */
    public function filter(Pool $pool, array $repositories, Request $request): Pool
    {
        $filterListMap = $this->collectFilterLists($pool, $repositories, $request);

        if (count($filterListMap) === 0) {
            return $pool;
        }

        $packages = [];
        $filterListRemovedVersions = [];
        foreach ($pool->getPackages() as $package) {
            $matchingEntries = $this->getMatchingEntries($package, $filterListMap);
            if (count($matchingEntries) > 0) {
                foreach ($package->getNames(false) as $packageName) {
                    $filterListRemovedVersions[$packageName][$package->getVersion()] = $matchingEntries;
                }

                continue;
            }

            $packages[] = $package;
        }

        return new Pool($packages, $pool->getUnacceptableFixedOrLockedPackages(), $pool->getAllRemovedVersions(), $pool->getAllRemovedVersionsByPackage(), $pool->getAllSecurityRemovedPackageVersions(), $pool->getAllAbandonedRemovedPackageVersions(), $filterListRemovedVersions);
    }

    /**
     * @param array<RepositoryInterface> $repositories
     * @return array<string, list<FilterListEntry>>
     */
    private function collectFilterLists(Pool $pool, array $repositories, Request $request): array
    {
        $packageNames = [];
        foreach ($pool->getPackages() as $package) {
            if (!$package instanceof RootPackageInterface && !PlatformRepository::isPlatformPackage($package->getName()) && !$request->isLockedPackage($package)) {
                foreach ($package->getNames(false) as $packageName) {
                    $packageNames[$packageName] = true;
                }
            }
        }

        if (count($packageNames) === 0) {
            return [];
        }

        $packageConstraintMap = [];
        foreach (array_keys($packageNames) as $name) {
            $packageConstraintMap[$name] = new MatchAllConstraint();
        }

        $filterListMap = [];
        foreach ($repositories as $repo) {
            if (!$repo instanceof FilterListProviderInterface || !$repo->hasFilter()) {
                continue;
            }

            foreach ($repo->getFilters($packageConstraintMap) as $entries) {
                foreach ($entries as $entry) {
                    $filterListMap[$entry->packageName][] = $entry;
                }
            }
        }

        return $filterListMap;
    }

    /**
     * @param array<string, list<FilterListEntry>> $filterListMap
     * @return list<FilterListEntry>
     */
    private function getMatchingEntries(PackageInterface $package, array $filterListMap): array
    {
        if ($package instanceof RootPackageInterface) {
            return [];
        }

        $matchingEntries = [];
        foreach ($package->getNames(false) as $packageName) {
            if (!isset($filterListMap[$packageName])) {
                continue;
            }

            $packageConstraint = new Constraint(Constraint::STR_OP_EQ, $package->getVersion());
            foreach ($filterListMap[$packageName] as $entry) {
                if ($entry->constraint->matches($packageConstraint)) {
                    $matchingEntries[] = $entry;
                }
            }
        }

        return $matchingEntries;
    }
}
