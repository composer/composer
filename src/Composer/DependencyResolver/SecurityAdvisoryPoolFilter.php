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
use Composer\Filter\PackageFilter\AbandonedRule;
use Composer\Filter\PackageFilter\SecurityAdvisoryRule;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Policy\PolicyConfig;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RepositorySet;

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
        if (!$this->policyConfig->advisories->block) {
            return $pool;
        }

        $repoSet = new RepositorySet();
        foreach ($repositories as $repo) {
            $repoSet->addRepository($repo);
        }

        $rejections = (new AbandonedRule($this->auditor, $this->policyConfig->abandoned))->getRejections($pool->getPackages());

        // Locked packages kept by a partial update are not blocked for their advisories
        $candidates = [];
        foreach ($pool->getPackages() as $index => $package) {
            $target = $package instanceof AliasPackage && $package->isRootPackageAlias() ? $package->getAliasOf() : $package;
            if (!isset($rejections[$index]) && !$request->isLockedPackage($target)) {
                $candidates[$index] = $package;
            }
        }
        $rejections += (new SecurityAdvisoryRule($this->auditor, $this->policyConfig, $repoSet, $this->io))->getRejections($candidates);

        $packages = [];
        $policyRemovedVersions = $pool->getAllPolicyRemovedVersions();
        foreach ($pool->getPackages() as $index => $package) {
            if (isset($rejections[$index])) {
                Pool::recordPolicyRemoval($policyRemovedVersions, $package, $rejections[$index]);
                continue;
            }

            $packages[] = $package;
        }

        return new Pool($packages, $pool->getUnacceptableFixedOrLockedPackages(), $pool->getAllRemovedVersions(), $pool->getAllRemovedVersionsByPackage(), $policyRemovedVersions);
    }
}
