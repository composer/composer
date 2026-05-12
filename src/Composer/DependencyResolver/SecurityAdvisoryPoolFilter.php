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

use Composer\Advisory\Auditor;
use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Advisory\SecurityAdvisory;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Policy\PolicyConfig;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RepositorySet;
use Composer\Semver\Constraint\Constraint;

/**
 * @internal
 */
class SecurityAdvisoryPoolFilter
{
    /** @var Auditor */
    private $auditor;
    /** @var PolicyConfig */
    private $policyConfig;
    /** @var IOInterface */
    private $io;

    public function __construct(
        Auditor $auditor,
        PolicyConfig $policyConfig,
        IOInterface $io
    ) {
        $this->auditor = $auditor;
        $this->policyConfig = $policyConfig;
        $this->io = $io;
    }

    /**
     * @param array<RepositoryInterface> $repositories
     */
    public function filter(Pool $pool, array $repositories, Request $request): Pool
    {
        $advisories = $this->policyConfig->advisories;
        $abandoned = $this->policyConfig->abandoned;

        if (!$advisories->block) {
            return $pool;
        }

        $repoSet = new RepositorySet();
        foreach ($repositories as $repo) {
            $repoSet->addRepository($repo);
        }

        $packagesForAdvisories = [];
        foreach ($pool->getPackages() as $package) {
            if (!$package instanceof RootPackageInterface && !PlatformRepository::isPlatformPackage($package->getName()) && !$request->isLockedPackage($package)) {
                $packagesForAdvisories[] = $package;
            }
        }

        $ignoreListForBlocking = $advisories->getIgnoreListForOperation('block');
        $ignoreUnreachableUpdate = $this->policyConfig->ignoreUnreachable->update;

        $allAdvisories = $repoSet->getMatchingSecurityAdvisories($packagesForAdvisories, true, $ignoreUnreachableUpdate);
        if ($this->auditor->needsCompleteAdvisoryLoad($allAdvisories['advisories'], $ignoreListForBlocking)) {
            $allAdvisories = $repoSet->getMatchingSecurityAdvisories($packagesForAdvisories, false, $ignoreUnreachableUpdate);
        }

        if ($ignoreUnreachableUpdate && count($allAdvisories['unreachableRepos']) > 0) {
            $this->io->writeError('<warning>Security advisory data could not be fetched from some repositories (ignored per policy.ignore-unreachable); matches may be incomplete:</warning>');
            foreach ($allAdvisories['unreachableRepos'] as $repo) {
                $this->io->writeError('  - ' . $repo);
            }
        }

        $advisoryMap = $this->auditor->processAdvisories($allAdvisories['advisories'], $ignoreListForBlocking, $advisories->getIgnoreSeverityForOperation('block'))['advisories'];

        $ignoreAbandonedForBlocking = $abandoned->getFlatIgnoreForOperation('block');

        $packages = [];
        $securityRemovedVersions = [];
        $abandonedRemovedVersions = [];
        foreach ($pool->getPackages() as $package) {
            if ($abandoned->block && count($this->auditor->filterAbandonedPackages([$package], $ignoreAbandonedForBlocking)) !== 0) {
                foreach ($package->getNames(false) as $packageName) {
                    $abandonedRemovedVersions[$packageName][$package->getVersion()] = $package->getPrettyVersion();
                }
                continue;
            }

            $matchingAdvisories = $this->getMatchingAdvisories($package, $advisoryMap);
            if (count($matchingAdvisories) > 0) {
                foreach ($package->getNames(false) as $packageName) {
                    $securityRemovedVersions[$packageName][$package->getVersion()] = $matchingAdvisories;
                }

                continue;
            }

            $packages[] = $package;
        }

        return new Pool($packages, $pool->getUnacceptableFixedOrLockedPackages(), $pool->getAllRemovedVersions(), $pool->getAllRemovedVersionsByPackage(), $securityRemovedVersions, $abandonedRemovedVersions);
    }

    /**
     * @param array<string, array<PartialSecurityAdvisory|SecurityAdvisory>> $advisoryMap
     * @return list<PartialSecurityAdvisory|SecurityAdvisory>
     */
    private function getMatchingAdvisories(PackageInterface $package, array $advisoryMap): array
    {
        if ($package->isDev()) {
            return [];
        }

        $matchingAdvisories = [];
        foreach ($package->getNames(false) as $packageName) {
            if (!isset($advisoryMap[$packageName])) {
                continue;
            }

            $packageConstraint = new Constraint(Constraint::STR_OP_EQ, $package->getVersion());
            foreach ($advisoryMap[$packageName] as $advisory) {
                if ($advisory->affectedVersions->matches($packageConstraint)) {
                    $matchingAdvisories[] = $advisory;
                }
            }
        }

        return $matchingAdvisories;
    }
}
