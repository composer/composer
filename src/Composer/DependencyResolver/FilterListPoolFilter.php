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
use Composer\FilterList\FilterListEntry;
use Composer\FilterList\FilterListProvider\FilterListProviderSet;
use Composer\Package\RootPackageInterface;
use Composer\Policy\ListPolicyConfig;
use Composer\Policy\PolicyConfig;
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
    /** @var PolicyConfig */
    private $policyConfig;
    /** @var FilterListAuditor */
    private $filterListAuditor;
    /** @var HttpDownloader */
    private $httpDownloader;
    /** @var ListPolicyConfig::BLOCK_SCOPE_* */
    private $blockScope;
    /** @var array<RepositoryInterface> */
    private $repositories;

    /**
     * @param ListPolicyConfig::BLOCK_SCOPE_* $blockScope
     * @param array<RepositoryInterface> $repositories Repositories consulted for filter list discovery
     */
    public function __construct(
        PolicyConfig $policyConfig,
        FilterListAuditor $filterListAuditor,
        HttpDownloader $httpDownloader,
        string $blockScope,
        array $repositories
    ) {
        $this->policyConfig = $policyConfig;
        $this->filterListAuditor = $filterListAuditor;
        $this->httpDownloader = $httpDownloader;
        $this->blockScope = $blockScope;
        $this->repositories = $repositories;
    }

    public function filter(Pool $pool, Request $request): Pool
    {
        $providerSet = FilterListProviderSet::create($this->policyConfig, $this->repositories, $this->httpDownloader);

        $filterListMap = $this->collectFilterLists($pool, $providerSet, $request);

        if (count($filterListMap) === 0) {
            return $pool;
        }

        $packages = [];
        $filterListRemovedVersions = [];
        foreach ($pool->getPackages() as $package) {
            $matchingEntries = $this->filterListAuditor->getMatchingBlockEntries($package, $filterListMap, $this->policyConfig, $this->blockScope);
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
            if ($package instanceof RootPackageInterface || PlatformRepository::isPlatformPackage($package->getName())) {
                continue;
            }
            // Locked packages can't change during an update (partial updates keep them fixed),
            // but install-scope blocking deliberately checks them so malware in the lock file is caught.
            if ($this->blockScope === ListPolicyConfig::BLOCK_SCOPE_UPDATE && $request->isLockedPackage($package)) {
                continue;
            }
            $packagesForFilter[] = $package;
        }

        return $this->filterListAuditor->collectFilterLists(
            $packagesForFilter,
            $providerSet,
            $this->policyConfig->getActiveBlockFilterListNames($this->blockScope),
            $this->policyConfig->ignoreUnreachable->forBlockScope($this->blockScope)
        )['filter'];
    }
}
