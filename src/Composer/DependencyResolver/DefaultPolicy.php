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
use Composer\Package\LinkConstraint\VersionConstraint;

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
        $constraint = new VersionConstraint($operator, $b->getVersion());
        $version = new VersionConstraint('==', $a->getVersion());

        return $constraint->matchSpecific($version);
    }

    public function findUpdatePackages(Solver $solver, Pool $pool, RepositoryInterface $repo, PackageInterface $package, $allowAll = false)
    {
        $packages = array();

        foreach ($pool->whatProvides($package->getName()) as $candidate) {
            // skip old packages unless downgrades are an option
            if (!$allowAll && !$this->allowDowngrade() && $this->versionCompare($package, $candidate, '>')) {
                continue;
            }

            if ($candidate !== $package) {
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

    public function selectPreferedPackages(Pool $pool, RepositoryInterface $installed, array $literals)
    {
        $packages = $this->groupLiteralsByNamePreferInstalled($installed, $literals);

        foreach ($packages as &$literals) {
            $policy = $this;
            usort($literals, function ($a, $b) use ($policy, $pool, $installed) {
                return $policy->compareByPriorityPreferInstalled($pool, $installed, $a->getPackage(), $b->getPackage());
            });
        }

        foreach ($packages as &$literals) {
            $literals = $this->pruneToBestVersion($literals);

            $literals = $this->pruneToHighestPriorityOrInstalled($pool, $installed, $literals);
        }

        $selected = call_user_func_array('array_merge', $packages);

        return $selected;
    }

    protected function groupLiteralsByNamePreferInstalled(RepositoryInterface $installed, $literals)
    {
        $packages = array();
        foreach ($literals as $literal) {
            $packageName = $literal->getPackage()->getName();

            if (!isset($packages[$packageName])) {
                $packages[$packageName] = array();
            }

            if ($literal->getPackage()->getRepository() === $installed) {
                array_unshift($packages[$packageName], $literal);
            } else {
                $packages[$packageName][] = $literal;
            }
        }

        return $packages;
    }

    public function compareByPriorityPreferInstalled(Pool $pool, RepositoryInterface $installed, PackageInterface $a, PackageInterface $b)
    {
        if ($a->getRepository() === $b->getRepository()) {
            return 0;
        }

        if ($a->getRepository() === $installed) {
            return -1;
        }

        if ($b->getRepository() === $installed) {
            return 1;
        }

        return ($pool->getPriority($a->getRepository()) > $pool->getPriority($b->getRepository())) ? -1 : 1;
    }

    protected function pruneToBestVersion($literals)
    {
        $bestLiterals = array($literals[0]);
        $bestPackage = $literals[0]->getPackage();
        foreach ($literals as $i => $literal) {
            if (0 === $i) {
                continue;
            }

            if ($this->versionCompare($literal->getPackage(), $bestPackage, '>')) {
                $bestPackage = $literal->getPackage();
                $bestLiterals = array($literal);
            } else if ($this->versionCompare($literal->getPackage(), $bestPackage, '==')) {
                $bestLiterals[] = $literal;
            }
        }

        return $bestLiterals;
    }

    protected function selectNewestPackages(RepositoryInterface $installed, array $literals)
    {
        $maxLiterals = array($literals[0]);
        $maxPackage = $literals[0]->getPackage();
        foreach ($literals as $i => $literal) {
            if (0 === $i) {
                continue;
            }

            if ($this->versionCompare($literal->getPackage(), $maxPackage, '>')) {
                $maxPackage = $literal->getPackage();
                $maxLiterals = array($literal);
            } else if ($this->versionCompare($literal->getPackage(), $maxPackage, '==')) {
                $maxLiterals[] = $literal;
            }
        }

        return $maxLiterals;
    }

    protected function pruneToHighestPriorityOrInstalled(Pool $pool, RepositoryInterface $installed, array $literals)
    {
        $selected = array();

        $priority = null;

        foreach ($literals as $literal) {
            $repo = $literal->getPackage()->getRepository();

            if ($repo === $installed) {
                $selected[] = $literal;
                continue;
            }

            if (null === $priority) {
                $priority = $pool->getPriority($repo);
            }

            if ($pool->getPriority($repo) != $priority) {
                break;
            }

            $selected[] = $literal;
        }

        return $selected;
    }
}
