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

namespace Composer\Filter\PackageFilter;

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
 * Rejects versions listed on the filter lists that block in the given scope, e.g. malware
 *
 * @internal
 * @final
 */
class FilterListRule implements RejectionRuleInterface
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
    /** @var (callable(BasePackage): bool)|null */
    private $isInstalledFromLock;

    /**
     * @param ListPolicyConfig::BLOCK_SCOPE_* $blockScope
     * @param array<RepositoryInterface> $repositories Repositories consulted for filter list discovery
     * @param (callable(BasePackage): bool)|null $isInstalledFromLock During an update, whether the package is about to be installed from the lock file, so the install scope lists (e.g. malware) apply to it instead
     */
    public function __construct(
        PolicyConfig $policyConfig,
        FilterListAuditor $filterListAuditor,
        HttpDownloader $httpDownloader,
        string $blockScope,
        array $repositories,
        IOInterface $io,
        ?callable $isInstalledFromLock = null
    ) {
        $this->policyConfig = $policyConfig;
        $this->filterListAuditor = $filterListAuditor;
        $this->httpDownloader = $httpDownloader;
        $this->blockScope = $blockScope;
        $this->repositories = $repositories;
        $this->io = $io;
        $this->isInstalledFromLock = $blockScope === ListPolicyConfig::BLOCK_SCOPE_UPDATE ? $isInstalledFromLock : null;
    }

    public function getRejections(array $packages): array
    {
        $checkLockedAgainstInstall = $this->isInstalledFromLock !== null;

        $configuredScopeListNames = $this->policyConfig->getActiveBlockFilterListNames($this->blockScope);
        $installScopeListNames = $checkLockedAgainstInstall
            ? $this->policyConfig->getActiveBlockFilterListNames(ListPolicyConfig::BLOCK_SCOPE_INSTALL)
            : [];
        $unionListNames = array_values(array_unique(array_merge($configuredScopeListNames, $installScopeListNames)));

        if (count($unionListNames) === 0) {
            return [];
        }

        // One fetch per provider for the union of both scopes' active lists.
        // Conservative ignoreUnreachable: only suppress transport errors when
        // both scopes opt in — otherwise the stricter scope wins.
        $ignoreUnreachable = $this->policyConfig->ignoreUnreachable->forBlockScope($this->blockScope);
        if ($checkLockedAgainstInstall) {
            $ignoreUnreachable = $ignoreUnreachable && $this->policyConfig->ignoreUnreachable->forBlockScope(ListPolicyConfig::BLOCK_SCOPE_INSTALL);
        }

        $providerSet = FilterListProviderSet::create($this->policyConfig, $this->repositories, $this->httpDownloader);
        $packagesForFilter = array_values(array_filter($packages, [self::class, 'isFilterable']));
        $fetchResult = $this->filterListAuditor->collectFilterLists($packagesForFilter, $providerSet, $unionListNames, $ignoreUnreachable);
        $unionMap = $fetchResult['filter'];

        if (count($fetchResult['unreachableRepos']) > 0) {
            $this->warnUnreachable($fetchResult['unreachableRepos']);
        }

        if (count($unionMap) === 0) {
            return [];
        }

        $configuredScopeMap = self::filterMapByListNames($unionMap, $configuredScopeListNames);
        $installScopeMap = $checkLockedAgainstInstall ? self::filterMapByListNames($unionMap, $installScopeListNames) : [];

        $rejections = [];
        foreach ($packages as $key => $package) {
            if (!self::isFilterable($package)) {
                continue;
            }

            if ($this->isInstalledFromLock !== null && ($this->isInstalledFromLock)($package)) {
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
                $rejections[$key] = RejectionReason::filterList($package->getName(), $package->getPrettyVersion(), $matchingEntries);
            }
        }

        return $rejections;
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
}
