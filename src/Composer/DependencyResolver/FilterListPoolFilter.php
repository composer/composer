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

use Composer\FilterList\FilterListConfig;
use Composer\FilterList\FilterListEntry;
use Composer\FilterList\FitlerListProvider\FilterListProviderSet;
use Composer\FilterList\FitlerListProvider\UrlSourceFilterListProvider;
use Composer\FilterList\Source\UrlSource;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Util\HttpDownloader;

/**
 * @internal
 * @final
 * @readonly
 */
class FilterListPoolFilter
{
    /** @var FilterListConfig */
    private $filterListConfig;
    /** @var HttpDownloader */
    private $httpDownloader;

    public function __construct(
        FilterListConfig $filterListConfig,
        HttpDownloader $httpDownloader
    ) {
        $this->filterListConfig = $filterListConfig;
        $this->httpDownloader = $httpDownloader;
    }

    /**
     * @param array<RepositoryInterface> $repositories
     */
    public function filter(Pool $pool, array $repositories, Request $request): Pool
    {
        $providerSet = new FilterListProviderSet(
            array_values($repositories),
            array_map(function (UrlSource $source) {
                return new UrlSourceFilterListProvider($this->httpDownloader, $source);
            }, $this->filterListConfig->getSources())
        );

        $filterListMap = $this->collectFilterLists($pool, $providerSet, $request);

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
     * @return array<string, array<string, list<FilterListEntry>>>
     */
    private function collectFilterLists(Pool $pool, FilterListProviderSet $providerSet, Request $request): array
    {
        $operation = 'block';
        $packagesForFilter = [];
        foreach ($pool->getPackages() as $package) {
            if (!$package instanceof RootPackageInterface && !PlatformRepository::isPlatformPackage($package->getName()) && !$request->isLockedPackage($package)) {
                $packagesForFilter[] = $package;
            }
        }

        $filters = $providerSet->getMatchingFilterLists($packagesForFilter, $this->filterListConfig->getConfiguredListNames($operation), $this->filterListConfig->ignoreUnreachable())['filter'];

        $filterListMap = [];
        foreach ($filters as $listName => $entries) {
            $listConfig = $this->filterListConfig->getListConfig($listName, $operation);
            if ($listConfig === null) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($listConfig->useCategory($entry->category)) {
                    $filterListMap[$entry->packageName][$entry->listName][] = $entry;
                }
            }
        }

        ksort($filterListMap);

        return $filterListMap;
    }

    /**
     * @param array<string, array<string, list<FilterListEntry>>> $filterListMap
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
            foreach ($filterListMap[$packageName] as $listName => $entries) {
                $filterListConfig = $this->filterListConfig->getListConfig($listName, 'block');
                if ($filterListConfig === null) {
                    continue;
                }

                if (isset($filterListConfig->dontFilterPackages[$packageName]) && $filterListConfig->dontFilterPackages[$packageName]->constraint->matches($packageConstraint)) {
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
