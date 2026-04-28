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
use Composer\Package\BasePackage;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Pcre\Preg;
use Composer\Policy\ListPolicyConfig;
use Composer\Policy\PolicyConfig;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;

/**
 * @internal
 * @final
 * @readonly
 */
class FilterListAuditor
{
    /**
     * @param PackageInterface[] $packages
     * @param list<string> $configuredLists
     * @return array{filter: array<string, array<string, list<FilterListEntry>>>, unreachableRepos: array<string>}
     */
    public function collectFilterLists(array $packages, FilterListProviderSet $providerSet, array $configuredLists, bool $ignoreUnreachable): array
    {
        $result = $providerSet->getMatchingFilterLists($packages, $configuredLists, $ignoreUnreachable);
        $filter = $result['filter'];
        $unreachableRepos = $result['unreachableRepos'];

        $filterListMap = [];
        foreach ($filter as $entries) {
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
    public function getMatchingEntries(PackageInterface $package, array $filterListMap, PolicyConfig $policyConfig, string $operation): array
    {
        if ($package instanceof RootPackageInterface || count($filterListMap) === 0) {
            return [];
        }

        $matchingEntries = [];
        $activeListConfigs = $policyConfig->getActiveFilterLists($operation);
        $allPackageNames = [];
        foreach ($activeListConfigs as $activeListConfig) {
            $allPackageNames = array_merge($allPackageNames, array_keys($activeListConfig->getIgnoreForOperation($operation)));
        }
        $allIgnoredPackageNamesRegex = BasePackage::packageNamesToRegexp($allPackageNames);

        foreach ($package->getNames(false) as $packageName) {
            if (!isset($filterListMap[$packageName])) {
                continue;
            }

            $packageConstraint = new Constraint(Constraint::STR_OP_EQ, $package->getVersion());
            $packageEntries = $filterListMap[$packageName];
            if (Preg::isMatch($allIgnoredPackageNamesRegex, $packageName)) {
                $unfilteredEntries = [];
                foreach ($filterListMap[$packageName] as $listName => $entries) {
                    if ($this->isPackageIgnored($packageName, $packageConstraint, $activeListConfigs[$listName], $operation)) {
                        continue;
                    }

                    $unfilteredEntries[$listName] = $entries;
                }

                $packageEntries = $unfilteredEntries;
            }

            foreach ($packageEntries as $entries) {
                foreach ($entries as $entry) {
                    if ($entry->constraint->matches($packageConstraint)) {
                        $matchingEntries[] = $entry;
                    }
                }
            }
        }

        return $matchingEntries;
    }

    /**
     * @param 'block'|'audit' $operation
     */
    private function isPackageIgnored(string $packageName, ConstraintInterface $packageConstraint, ListPolicyConfig $listConfig, string $operation): bool
    {
        foreach ($listConfig->getIgnoreForOperation($operation) as $ignorePackageRules) {
            foreach ($ignorePackageRules as $ignorePackageRule) {
                if (Preg::isMatch($ignorePackageRule->packageNameRegex, $packageName) && $ignorePackageRule->constraint->matches($packageConstraint)) {
                    return true;
                }
            }
        }

        return false;
    }
}
