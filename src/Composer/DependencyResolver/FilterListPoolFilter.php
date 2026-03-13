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

use Composer\FilterList\FilterListAuditor;
use Composer\FilterList\FilterListConfig;
use Composer\FilterList\FilterListEntry;
use Composer\FilterList\FitlerListProvider\FilterListProviderSet;
use Composer\Package\RootPackageInterface;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
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
    /** @var FilterListAuditor */
    private $filterListAuditor;
    /** @var HttpDownloader */
    private $httpDownloader;

    public function __construct(
        FilterListConfig $filterListConfig,
        FilterListAuditor $filterListAuditor,
        HttpDownloader $httpDownloader
    ) {
        $this->filterListConfig = $filterListConfig;
        $this->filterListAuditor = $filterListAuditor;
        $this->httpDownloader = $httpDownloader;
    }

    /**
     * @param array<RepositoryInterface> $repositories
     */
    public function filter(Pool $pool, array $repositories, Request $request): Pool
    {
        $providerSet = FilterListProviderSet::create($this->filterListConfig, $repositories, $this->httpDownloader);

        $filterListMap = $this->collectFilterLists($pool, $providerSet, $request);

        if (count($filterListMap) === 0) {
            return $pool;
        }

        $packages = [];
        $filterListRemovedVersions = [];
        foreach ($pool->getPackages() as $package) {
            $matchingEntries = $this->filterListAuditor->getMatchingEntries($package, $filterListMap, $this->filterListConfig, 'block');
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
        $packagesForFilter = [];
        foreach ($pool->getPackages() as $package) {
            if (!$package instanceof RootPackageInterface && !PlatformRepository::isPlatformPackage($package->getName()) && !$request->isLockedPackage($package)) {
                $packagesForFilter[] = $package;
            }
        }

        return $this->filterListAuditor->collectFilterLists($packagesForFilter, $providerSet, $this->filterListConfig, 'block', $this->filterListConfig->ignoreUnreachable())['filter'];
    }
}
