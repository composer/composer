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
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RepositorySet;
use Composer\Semver\Constraint\Constraint;

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
        $repoSet = new RepositorySet();
        foreach ($repositories as $repo) {
            $repoSet->addRepository($repo);
        }

        $packagesForAdvisories = [];
        foreach ($pool->getPackages() as $package) {
            // @todo Pool contains a list of ext-/lib-/php/composer/composer-plugin-api/composer-runtime-api that need to be filtered out before fetching security advisories. Is there a better way?
            if (! $package instanceof RootPackageInterface && str_contains($package->getName(), '/')) {
                $packagesForAdvisories[] = $package;
            }
        }

        $allAdvisories = $repoSet->getMatchingSecurityAdvisories($packagesForAdvisories, true);
        $advisoryMap = $this->auditor->processAdvisories($allAdvisories, $this->auditConfig->ignoreList)['advisories'];

        $packages = [];
        $securityRemovedVersions = [];
        foreach ($pool->getPackages() as $package) {
            if ($this->doesPackageMatchAdvisories($package, $advisoryMap)) {
                foreach ($package->getNames(false) as $packageName) {
                    $securityRemovedVersions[$packageName][$package->getVersion()] = $package->getPrettyVersion();
                }
            } else {
                $packages[] = $package;
            }
        }

        return new Pool($packages, $pool->getUnacceptableFixedOrLockedPackages(), [], [], $securityRemovedVersions);
    }

    /**
     * @param array<string, array<PartialSecurityAdvisory|SecurityAdvisory>> $advisoryMap
     */
    private function doesPackageMatchAdvisories(PackageInterface $package, array $advisoryMap): bool
    {
        if ($package->isDev()) {
            return false;
        }

        foreach ($package->getNames(false) as $packageName) {
            if (! isset($advisoryMap[$packageName])) {
                return false;
            }

            $packageConstraint = new Constraint(Constraint::STR_OP_EQ, $package->getVersion());
            foreach ($advisoryMap[$packageName] as $advisory) {
                if ($advisory->affectedVersions->matches($packageConstraint)) {
                    return true;
                }
            }
        }

        return false;
    }
}
