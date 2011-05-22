<?php

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

use Composer\Repository\RepositoryInterface;
use Composer\Package\PackageInterface;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class DefaultPolicy implements PolicyInterface
{
    public function allowUninstall()
    {
        return false;
    }

    public function allowDowngrade()
    {
        return false;
    }

    public function versionCompare(PackageInterface $a, PackageInterface $b, $operator)
    {
        return version_compare($a->getVersion(), $b->getVersion(), $operator);
    }

    public function findUpdatePackages(Solver $solver, Pool $pool, RepositoryInterface $repo, PackageInterface $package, $allowAll = false)
    {
        $packages = array();

        foreach ($pool->whatProvides($package->getName()) as $candidate) {
            // skip old packages unless downgrades are an option
            if (!$allowAll && !$this->allowDowngrade() && $this->versionCompare($package, $candidate, '>')) {
                continue;
            }

            if ($candidate != $package) {
                $packages[] = $candidate;
            }
        }

        return $packages;
    }

    public function installable(Solver $solver, Pool $pool, RepositoryInterface $repo, PackageInterface $package)
    {
        // todo: package blacklist?
        return true;
    }

    public function selectPreferedPackages(array $literals)
    {
        // todo: prefer installed, recommended, highest priority repository, ...
        return array($literals[0]);
    }
}
