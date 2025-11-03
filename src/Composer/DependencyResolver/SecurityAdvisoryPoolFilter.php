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
use Composer\Package\CompletePackage;
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

    public function __construct(
        Auditor $auditor,
        AuditConfig $auditConfig
    ) {
        $this->auditor = $auditor;
        $this->auditConfig = $auditConfig;
    }

    /**
     * @param array<RepositoryInterface> $repositories
     */
    public function filter(Pool $pool, array $repositories): Pool
    {
        $advisoryMap = [];
        if ($this->auditConfig->blockInsecure) {
            $repoSet = new RepositorySet();
            foreach ($repositories as $repo) {
                $repoSet->addRepository($repo);
            }

            $packagesForAdvisories = [];
            foreach ($pool->getPackages() as $package) {
                if (!$package instanceof RootPackageInterface && !PlatformRepository::isPlatformPackage($package->getName())) {
                    $packagesForAdvisories[] = $package;
                }
            }

            $allAdvisories = $repoSet->getMatchingSecurityAdvisories($packagesForAdvisories, true);
            $advisoryMap = $this->auditor->processAdvisories($allAdvisories['advisories'], $this->auditConfig->ignoreList, [])['advisories'];
        }

        $packages = [];
        $securityRemovedVersions = [];
        $abandonedRemovedVersions = [];
        foreach ($pool->getPackages() as $package) {
            if ($this->auditConfig->blockAbandoned && count($this->auditor->filterAbandonedPackages([$package], $this->auditConfig->ignoreAbandonedPackages)) !== 0) {
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

        return new Pool($packages, $pool->getUnacceptableFixedOrLockedPackages(), [], [], $securityRemovedVersions, $abandonedRemovedVersions);
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
