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

use Composer\Advisory\AuditConfig;
use Composer\Advisory\Auditor;
use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Advisory\SecurityAdvisory;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
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
    /** @var AuditConfig $auditConfig */
    private $auditConfig;
    /** @var array<string, array<PartialSecurityAdvisory|SecurityAdvisory>> */
    private $advisoryMap = [];

    public function __construct(
        Auditor $auditor,
        AuditConfig $auditConfig
    ) {
        $this->auditor = $auditor;
        $this->auditConfig = $auditConfig;
    }

    /**
     * Get the advisory map built during filtering
     *
     * This allows other filters (like ReleaseAgePoolFilter) to identify
     * security fixes that should bypass release age restrictions.
     *
     * @return array<string, array<PartialSecurityAdvisory|SecurityAdvisory>>
     */
    public function getAdvisoryMap(): array
    {
        return $this->advisoryMap;
    }

    /**
     * @param array<RepositoryInterface> $repositories
     */
    public function filter(Pool $pool, array $repositories, Request $request): Pool
    {
        if (!$this->auditConfig->blockInsecure) {
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

        $allAdvisories = $repoSet->getMatchingSecurityAdvisories($packagesForAdvisories, true, true);
        if ($this->auditor->needsCompleteAdvisoryLoad($allAdvisories['advisories'], $this->auditConfig->ignoreListForBlocking)) {
            $allAdvisories = $repoSet->getMatchingSecurityAdvisories($packagesForAdvisories, false, true);
        }

        $this->advisoryMap = $this->auditor->processAdvisories($allAdvisories['advisories'], $this->auditConfig->ignoreListForBlocking, $this->auditConfig->ignoreSeverityForBlocking)['advisories'];

        $packages = [];
        $securityRemovedVersions = [];
        $abandonedRemovedVersions = [];
        foreach ($pool->getPackages() as $package) {
            if ($this->auditConfig->blockAbandoned && count($this->auditor->filterAbandonedPackages([$package], $this->auditConfig->ignoreAbandonedForBlocking)) !== 0) {
                foreach ($package->getNames(false) as $packageName) {
                    $abandonedRemovedVersions[$packageName][$package->getVersion()] = $package->getPrettyVersion();
                }
                continue;
            }

            $matchingAdvisories = $this->getMatchingAdvisories($package, $this->advisoryMap);
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
