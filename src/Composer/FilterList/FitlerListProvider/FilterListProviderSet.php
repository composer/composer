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

namespace Composer\FilterList\FitlerListProvider;

use Composer\FilterList\FilterListConfig;
use Composer\FilterList\FilterListEntry;
use Composer\FilterList\Source\UrlSource;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Repository\FilterListProviderInterface;
use Composer\Repository\RepositoryInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\Util\HttpDownloader;

/**
 * @internal
 * @readonly
 * @final
 */
class FilterListProviderSet
{
    /** @var list<FilterListProviderInterface> */
    private $providers;

    /**
     * @param list<RepositoryInterface> $repositories
     * @param list<FilterListProviderInterface> $sources
     */
    public function __construct(array $repositories, array $sources)
    {
        $providers = $sources;
        foreach ($repositories as $repository) {
            if ($repository instanceof FilterListProviderInterface && $repository->hasFilter()) {
                $providers[] = $repository;
            }
        }

        $this->providers = $providers;
    }

    /**
     * @param array<RepositoryInterface> $repositories
     */
    public static function create(FilterListConfig $config, array $repositories, HttpDownloader $httpDownloader): self
    {
        return new FilterListProviderSet(
            array_values($repositories),
            array_map(
                function (UrlSource $source) use ($httpDownloader) {
                    return new UrlSourceFilterListProvider($httpDownloader, $source);
                },
                $config->getSources()
            )
        );
    }

    /**
     * @param PackageInterface[] $packages
     * @param list<string> $lists
     * @return array{filter: array<string, array<FilterListEntry>>, unreachableRepos: array<string>}
     */
    public function getMatchingFilterLists(array $packages, array $lists, bool $ignoreUnreachable = false): array
    {
        $map = [];
        foreach ($packages as $package) {
            // ignore root alias versions as they are not actual package versions and should not matter when it comes to vulnerabilities
            if ($package instanceof AliasPackage && $package->isRootPackageAlias()) {
                continue;
            }
            if (isset($map[$package->getName()])) {
                $map[$package->getName()] = new MultiConstraint([new Constraint('=', $package->getVersion()), $map[$package->getName()]], false);
            } else {
                $map[$package->getName()] = new Constraint('=', $package->getVersion());
            }
        }

        $unreachableRepos = [];
        $filters = $this->getFilterListEntriesForConstraints($map, $lists, $ignoreUnreachable, $unreachableRepos);

        return ['filter' => $filters, 'unreachableRepos' => $unreachableRepos];
    }

    /**
     * @param array<string, ConstraintInterface> $packageConstraintMap
     * @param list<string> $lists
     * @param array<string> &$unreachableRepos Array to store messages about unreachable repositories
     * @return array<string, list<FilterListEntry>>
     */
    private function getFilterListEntriesForConstraints(array $packageConstraintMap, array $lists, bool $ignoreUnreachable = false, array &$unreachableRepos = []): array
    {
        $filters = [];
        foreach ($this->providers as $provider) {
            try {
                $repoFilter = $provider->getFilter($packageConstraintMap, $lists)['filter'];
                foreach ($repoFilter as $listName => $entries) {
                    if ($lists !== [] && !in_array($listName, $lists, true)) {
                        continue;
                    }

                    foreach ($entries as $entry) {
                        if (!isset($packageConstraintMap[$entry->packageName])) {
                            continue;
                        }

                        if (!$entry->constraint->matches($packageConstraintMap[$entry->packageName])) {
                            continue;
                        }

                        $filters[$listName][] = $entry;
                    }
                }

            } catch (\Composer\Downloader\TransportException $e) {
                if (!$ignoreUnreachable) {
                    throw $e;
                }
                $unreachableRepos[] = $e->getMessage();
            }
        }

        ksort($filters);

        return $filters;
    }

}
