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
use Composer\IO\IOInterface;
use Composer\Package\BasePackage;
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
    /** @var IOInterface */
    private $io;

    /**
     * @param ListPolicyConfig::BLOCK_SCOPE_* $blockScope
     * @param array<RepositoryInterface> $repositories Repositories consulted for filter list discovery
     */
    public function __construct(
        PolicyConfig $policyConfig,
        FilterListAuditor $filterListAuditor,
        HttpDownloader $httpDownloader,
        string $blockScope,
        array $repositories,
        IOInterface $io
    ) {
        $this->policyConfig = $policyConfig;
        $this->filterListAuditor = $filterListAuditor;
        $this->httpDownloader = $httpDownloader;
        $this->blockScope = $blockScope;
        $this->repositories = $repositories;
        $this->io = $io;
    }

    public function filter(Pool $pool, Request $request): Pool
    {
        // During UPDATE scope, packages that are locked are about to be installed
        // and must also be checked against install-scope filter lists (e.g. malware).
        $checkLockedAgainstInstall = $this->blockScope === ListPolicyConfig::BLOCK_SCOPE_UPDATE;

        $configuredScopeListNames = $this->policyConfig->getActiveBlockFilterListNames($this->blockScope);
        $installScopeListNames = $checkLockedAgainstInstall
            ? $this->policyConfig->getActiveBlockFilterListNames(ListPolicyConfig::BLOCK_SCOPE_INSTALL)
            : [];

        $unionListNames = array_values(array_unique(array_merge($configuredScopeListNames, $installScopeListNames)));
        if (count($unionListNames) === 0) {
            return $pool;
        }

        // One fetch per provider for the union of both scopes' active lists.
        // Conservative ignoreUnreachable: only suppress transport errors when
        // both scopes opt in — otherwise the stricter scope wins.
        $ignoreUnreachable = $this->policyConfig->ignoreUnreachable->forBlockScope($this->blockScope);
        if ($checkLockedAgainstInstall) {
            $ignoreUnreachable = $ignoreUnreachable && $this->policyConfig->ignoreUnreachable->forBlockScope(ListPolicyConfig::BLOCK_SCOPE_INSTALL);
        }

        $providerSet = FilterListProviderSet::create($this->policyConfig, $this->repositories, $this->httpDownloader);
        $fetchResult = $this->fetchFilterListMap($pool, $providerSet, $unionListNames, $ignoreUnreachable);
        $unionMap = $fetchResult['filter'];
        if (count($fetchResult['unreachableRepos']) > 0) {
            $this->warnUnreachable($fetchResult['unreachableRepos']);
        }

        if (count($unionMap) === 0) {
            return $pool;
        }

        $configuredScopeMap = self::filterMapByListNames($unionMap, $configuredScopeListNames);
        $installScopeMap = $checkLockedAgainstInstall ? self::filterMapByListNames($unionMap, $installScopeListNames) : [];

        $lockedNameVersionMap = $checkLockedAgainstInstall ? $this->buildLockedNameVersionMap($request) : [];

        $packages = [];
        $filterListRemovedVersions = [];
        foreach ($pool->getPackages() as $package) {
            if (!self::isFilterable($package)) {
                $packages[] = $package;
                continue;
            }

            if ($checkLockedAgainstInstall && $this->isLockedEquivalent($package, $request, $lockedNameVersionMap)) {
                $matchingEntries = $this->filterListAuditor->getMatchingBlockEntries(
                    $package,
                    $installScopeMap,
                    $this->policyConfig,
                    ListPolicyConfig::BLOCK_SCOPE_INSTALL
                );
            } else {
                $matchingEntries = $this->filterListAuditor->getMatchingBlockEntries(
                    $package,
                    $configuredScopeMap,
                    $this->policyConfig,
                    $this->blockScope
                );
            }

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
     * @param list<string> $listNames
     * @return array{filter: array<string, array<string, list<FilterListEntry>>>, unreachableRepos: array<string>}
     */
    private function fetchFilterListMap(Pool $pool, FilterListProviderSet $providerSet, array $listNames, bool $ignoreUnreachable): array
    {
        $packagesForFilter = [];
        foreach ($pool->getPackages() as $package) {
            if (!self::isFilterable($package)) {
                continue;
            }

            $packagesForFilter[] = $package;
        }

        return $this->filterListAuditor->collectFilterLists(
            $packagesForFilter,
            $providerSet,
            $listNames,
            $ignoreUnreachable
        );
    }

    /**
     * @param array<string> $unreachableRepos
     */
    private function warnUnreachable(array $unreachableRepos): void
    {
        $this->io->writeError('<warning>Filter list data could not be fetched from some sources (ignored per policy.ignore-unreachable); matches may be incomplete:</warning>');
        foreach ($unreachableRepos as $repo) {
            $this->io->writeError('  - ' . $repo);
        }
    }

    /**
     * Restrict a packageName => listName => entries map to the given list names.
     *
     * Used to split a single union-fetched filter map into per-scope maps that
     * `FilterListAuditor::matchingEntries()` can safely consume. The auditor's
     * non-ignored-package code path does not re-filter entries by active list,
     * so each map MUST only contain entries from lists active for its scope.
     *
     * @param array<string, array<string, list<FilterListEntry>>> $map
     * @param list<string> $listNames
     * @return array<string, array<string, list<FilterListEntry>>>
     */
    private static function filterMapByListNames(array $map, array $listNames): array
    {
        if (count($listNames) === 0) {
            return [];
        }

        $allowed = array_flip($listNames);
        $filtered = [];
        foreach ($map as $packageName => $entriesByList) {
            foreach ($entriesByList as $listName => $entries) {
                if (isset($allowed[$listName])) {
                    $filtered[$packageName][$listName] = $entries;
                }
            }
        }

        return $filtered;
    }

    private static function isFilterable(BasePackage $package): bool
    {
        return !($package instanceof RootPackageInterface) && !PlatformRepository::isPlatformPackage($package->getName());
    }

    /**
     * Build a name => normalized-version-set lookup of the locked repository.
     *
     * Identity-based detection (`isLockedPackage`) only fires for packages the
     * solver explicitly locked via `Request::lockPackage()` — that covers
     * partial updates with an allow list, but NOT `update mirrors`, which
     * pins packages via `requireName(==version)` and loads fresh package
     * instances from configured repos. Matching by name + normalized version
     * recognises those pool packages as locked-equivalent too.
     *
     * @return array<string, array<string, true>>
     */
    private function buildLockedNameVersionMap(Request $request): array
    {
        $map = [];
        $lockedRepository = $request->getLockedRepository();
        if ($lockedRepository !== null) {
            foreach ($lockedRepository->getPackages() as $lockedPackage) {
                $map[$lockedPackage->getName()][$lockedPackage->getVersion()] = true;
            }
        }

        return $map;
    }

    /**
     * @param array<string, array<string, true>> $lockedNameVersionMap
     */
    private function isLockedEquivalent(BasePackage $package, Request $request, array $lockedNameVersionMap): bool
    {
        if ($request->isLockedPackage($package)) {
            return true;
        }

        return isset($lockedNameVersionMap[$package->getName()][$package->getVersion()]);
    }
}
