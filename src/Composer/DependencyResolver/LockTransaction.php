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

use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\Package;
use Composer\Pcre\Preg;

/**
 * @author Nils Adermann <naderman@naderman.de>
 * @internal
 */
class LockTransaction extends Transaction
{
    /**
     * packages in current lock file, platform repo or otherwise present
     *
     * Indexed by spl_object_hash
     *
     * @var array<string, BasePackage>
     */
    protected $presentMap;

    /**
     * Packages which cannot be mapped, platform repo, root package, other fixed repos
     *
     * Indexed by package id
     *
     * @var array<int, BasePackage>
     */
    protected $unlockableMap;

    /**
     * @var array{dev: BasePackage[], non-dev: BasePackage[], all: BasePackage[]}
     */
    protected $resultPackages;

    /**
     * @param array<string, BasePackage> $presentMap
     * @param array<int, BasePackage> $unlockableMap
     */
    public function __construct(Pool $pool, array $presentMap, array $unlockableMap, Decisions $decisions)
    {
        $this->presentMap = $presentMap;
        $this->unlockableMap = $unlockableMap;

        $this->setResultPackages($pool, $decisions);
        parent::__construct($this->presentMap, $this->resultPackages['all']);
    }

    // TODO make this a bit prettier instead of the two text indexes?

    public function setResultPackages(Pool $pool, Decisions $decisions): void
    {
        $this->resultPackages = ['all' => [], 'non-dev' => [], 'dev' => []];
        foreach ($decisions as $i => $decision) {
            $literal = $decision[Decisions::DECISION_LITERAL];

            if ($literal > 0) {
                $package = $pool->literalToPackage($literal);

                $this->resultPackages['all'][] = $package;
                if (!isset($this->unlockableMap[$package->id])) {
                    $this->resultPackages['non-dev'][] = $package;
                }
            }
        }
    }

    public function setNonDevPackages(LockTransaction $extractionResult): void
    {
        $packages = $extractionResult->getNewLockPackages(false);

        $this->resultPackages['dev'] = $this->resultPackages['non-dev'];
        $this->resultPackages['non-dev'] = [];

        foreach ($packages as $package) {
            foreach ($this->resultPackages['dev'] as $i => $resultPackage) {
                // TODO this comparison is probably insufficient, aliases, what about modified versions? I guess they aren't possible?
                if ($package->getName() === $resultPackage->getName()) {
                    $this->resultPackages['non-dev'][] = $resultPackage;
                    unset($this->resultPackages['dev'][$i]);
                }
            }
        }
    }

    // TODO additionalFixedRepository needs to be looked at here as well?
    /**
     * @return BasePackage[]
     */
    public function getNewLockPackages(bool $devMode, bool $updateMirrors = false): array
    {
        $packages = [];
        foreach ($this->resultPackages[$devMode ? 'dev' : 'non-dev'] as $package) {
            if ($package instanceof AliasPackage) {
                continue;
            }

            // if we're just updating mirrors we need to reset everything to the same as currently "present" packages' references to keep the lock file as-is
            if ($updateMirrors === true && !array_key_exists(spl_object_hash($package), $this->presentMap)) {
                $package = $this->updateMirrorAndUrls($package);
            }

            $packages[] = $package;
        }

        return $packages;
    }

    /**
     * Try to return the original package from presentMap with updated URLs/mirrors
     *
     * If the type of source/dist changed, then we do not update those and keep them as they were
     */
    private function updateMirrorAndUrls(BasePackage $package): BasePackage
    {
        foreach ($this->presentMap as $presentPackage) {
            if ($package->getName() !== $presentPackage->getName()) {
                continue;
            }

            if ($package->getVersion() !== $presentPackage->getVersion()) {
                continue;
            }

            if ($presentPackage->getSourceReference() === null) {
                continue;
            }

            if ($presentPackage->getSourceType() !== $package->getSourceType()) {
                continue;
            }

            if ($presentPackage instanceof Package) {
                $presentPackage->setSourceUrl($package->getSourceUrl());
                $presentPackage->setSourceMirrors($package->getSourceMirrors());
            }

            // if the dist type changed, we only update the source url/mirrors
            if ($presentPackage->getDistType() !== $package->getDistType()) {
                return $presentPackage;
            }

            // update dist url if it is in a known format
            if (
                $package->getDistUrl() !== null
                && $presentPackage->getDistReference() !== null
                && Preg::isMatch('{^https?://(?:(?:www\.)?bitbucket\.org|(api\.)?github\.com|(?:www\.)?gitlab\.com)/}i', $package->getDistUrl())
            ) {
                $presentPackage->setDistUrl(Preg::replace('{(?<=/|sha=)[a-f0-9]{40}(?=/|$)}i', $presentPackage->getDistReference(), $package->getDistUrl()));
            }
            $presentPackage->setDistMirrors($package->getDistMirrors());

            return $presentPackage;
        }

        return $package;
    }

    /**
     * Checks which of the given aliases from composer.json are actually in use for the lock file
     * @param list<array{package: string, version: string, alias: string, alias_normalized: string}> $aliases
     * @return list<array{package: string, version: string, alias: string, alias_normalized: string}>
     */
    public function getAliases(array $aliases): array
    {
        $usedAliases = [];

        foreach ($this->resultPackages['all'] as $package) {
            if ($package instanceof AliasPackage) {
                foreach ($aliases as $index => $alias) {
                    if ($alias['package'] === $package->getName()) {
                        $usedAliases[] = $alias;
                        unset($aliases[$index]);
                    }
                }
            }
        }

        usort($usedAliases, static function ($a, $b): int {
            return strcmp($a['package'], $b['package']);
        });

        return $usedAliases;
    }
}
