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

namespace Composer\FilterList;

use Composer\FilterList\FilterListProvider\FilterListProviderSet;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Semver\Constraint\Constraint;

/**
 * @internal
 * @final
 * @readonly
 */
class FilterListAuditor
{
    /**
     * @param PackageInterface[] $packages
     * @param 'block'|'audit' $operation
     * @return array{filter: array<string, array<string, list<FilterListEntry>>>, unreachableRepos: array<string>}
     */
    public function collectFilterLists(array $packages, FilterListProviderSet $providerSet, FilterListConfig $filterListConfig, string $operation, bool $ignoreUnreachable): array
    {
        $result = $providerSet->getMatchingFilterLists($packages, $filterListConfig->getConfiguredListNames($operation), $ignoreUnreachable);
        $filter = $result['filter'];
        $unreachableRepos = $result['unreachableRepos'];

        $filterListMap = [];
        foreach ($filter as $listName => $entries) {
            $listConfig = $filterListConfig->getListConfig($listName, $operation);
            if ($listConfig === null) {
                continue;
            }

            foreach ($entries as $entry) {
                $filterListMap[$entry->packageName][$entry->listName][] = $entry;
            }
        }

        ksort($filterListMap);

        return ['filter' => $filterListMap, 'unreachableRepos' => $unreachableRepos];
    }

    /**
     * @param array<string, array<string, list<FilterListEntry>>> $filterListMap
     * @param 'block'|'audit' $operation
     * @return list<FilterListEntry>
     */
    public function getMatchingEntries(PackageInterface $package, array $filterListMap, FilterListConfig $filterListConfig, string $operation): array
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
            foreach ($filterListMap[$packageName] as $listName => $entries) {
                $listConfig = $filterListConfig->getListConfig($listName, $operation);
                if ($listConfig === null) {
                    continue;
                }

                if (isset($listConfig->dontFilterPackages[$packageName]) && $listConfig->dontFilterPackages[$packageName]->constraint->matches($packageConstraint)) {
                    continue;
                }

                foreach ($entries as $entry) {
                    if ($entry->constraint->matches($packageConstraint)) {
                        $matchingEntries[] = $entry;
                    }
                }
            }
        }

        return $matchingEntries;
    }
}
