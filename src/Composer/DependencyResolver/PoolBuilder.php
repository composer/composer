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

use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\PackageInterface;
use Composer\Repository\AsyncRepositoryInterface;
use Composer\Repository\ComposerRepository;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Repository\LockArrayRepository;
use Composer\Repository\PlatformRepository;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MultiConstraint;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class PoolBuilder
{
    private $isPackageAcceptableCallable;
    private $filterRequires;
    private $rootAliases;

    private $aliasMap = array();
    private $nameConstraints = array();

    private $loadedNames = array();

    private $packages = array();
    private $priorities = array();

    public function __construct($isPackageAcceptableCallable, array $filterRequires = array())
    {
        $this->isPackageAcceptableCallable = $isPackageAcceptableCallable;
        $this->filterRequires = $filterRequires;
    }

    public function buildPool(array $repositories, array $rootAliases, Request $request)
    {
        $pool = new Pool($this->filterRequires);
        $this->rootAliases = $rootAliases;

        // TODO do we really want the request here? kind of want a root requirements thingy instead
        $loadNames = array();
        foreach ($request->getJobs() as $job) {
            switch ($job['cmd']) {
                case 'install':
                    $loadNames[$job['packageName']] = $job['constraint'];
                    $this->nameConstraints[$job['packageName']] = $job['constraint'] ? new MultiConstraint(array($job['constraint']), false) : null;
                    break;
            }
        }

        while (!empty($loadNames)) {
            $loadIds = array();
            foreach ($repositories as $key => $repository) {
                if ($repository instanceof AsyncRepositoryInterface) {
                    $loadIds[$key] = $repository->requestPackages($loadNames);
                }
            }

            foreach ($loadNames as $name => $void) {
                $this->loadedNames[$name] = true;
            }

            $newLoadNames = array();
            foreach ($repositories as $key => $repository) {
                if ($repository instanceof PlatformRepository || $repository instanceof InstalledRepositoryInterface) {
                    continue;
                }

                if ($repository instanceof AsyncRepositoryInterface) {
                    // TODO ispackageacceptablecallable in here?
                    $packages = $repository->returnPackages($loadIds[$key]);
                } else {
                    // TODO should we really pass the callable into here?
                    $packages = $repository->loadPackages($loadNames, $this->isPackageAcceptableCallable);
                }

                foreach ($packages as $package) {
                    if (call_user_func($this->isPackageAcceptableCallable, $package->getNames(), $package->getStability())) {
                        $newLoadNames += $this->loadPackage($package, $key);
                    }
                }
            }

            $loadNames = $newLoadNames;
        }

        foreach ($this->packages as $i => $package) {
            // we check all alias related packages at once, so no need ot check individual aliases
            // isset also checks non-null value
            if (!$package instanceof AliasPackage && isset($this->nameConstraints[$package->getName()])) {
                $constraint = $this->nameConstraints[$package->getName()];

                $aliasedPackages = array($i => $package);
                if (isset($this->aliasMap[spl_object_hash($package)])) {
                    $aliasedPackages += $this->aliasMap[spl_object_hash($package)];
                }

                $found = false;
                foreach ($aliasedPackages as $packageOrAlias) {
                    if ($constraint->matches(new Constraint('==', $packageOrAlias->getVersion()))) {
                        $found = true;
                    }
                }
                if (!$found) {
                    foreach ($aliasedPackages as $index => $packageOrAlias) {
                        unset($this->packages[$index]);
                        unset($this->priorities[$index]);
                    }
                }
            }
        }

        foreach ($repositories as $key => $repository) {
            if ($repository instanceof PlatformRepository ||
                $repository instanceof InstalledRepositoryInterface) {
                foreach ($repository->getPackages() as $package) {
                    $this->loadPackage($package, $key);
                }
            }
        }

        $pool->setPackages($this->packages, $this->priorities);

        unset($this->aliasMap);
        unset($this->loadedNames);
        unset($this->nameConstraints);

        return $pool;
    }

    private function loadPackage(PackageInterface $package, $repoIndex)
    {
        $index = count($this->packages);
        $this->packages[] = $package;
        $this->priorities[] = -$repoIndex;

        if ($package instanceof AliasPackage) {
            $this->aliasMap[spl_object_hash($package->getAliasOf())][$index] = $package;
        }

        // handle root package aliases
        $name = $package->getName();
        if (isset($this->rootAliases[$name][$package->getVersion()])) {
            $alias = $this->rootAliases[$name][$package->getVersion()];
            if ($package instanceof AliasPackage) {
                $basePackage = $package->getAliasOf();
            } else {
                $basePackage = $package;
            }
            $aliasPackage = new AliasPackage($basePackage, $alias['alias_normalized'], $alias['alias']);
            $aliasPackage->setRootPackageAlias(true);

            $package->getRepository()->addPackage($aliasPackage); // TODO do we need this?
            $this->packages[] = $aliasPackage;
            $this->priorities[] = -$repoIndex;
            $this->aliasMap[spl_object_hash($aliasPackage->getAliasOf())][$index+1] = $aliasPackage;
        }

        $loadNames = array();
        foreach ($package->getRequires() as $link) {
            $require = $link->getTarget();
            if (!isset($this->loadedNames[$require])) {
                $loadNames[$require] = null;
            }
            if ($link->getConstraint()) {
                if (!array_key_exists($require, $this->nameConstraints)) {
                    $this->nameConstraints[$require] = new MultiConstraint(array($link->getConstraint()), false);
                } elseif ($this->nameConstraints[$require]) {
                    // TODO addConstraint function?
                    $this->nameConstraints[$require] = new MultiConstraint(array_merge(array($link->getConstraint()), $this->nameConstraints[$require]->getConstraints()), false);
                }
            } else {
                $this->nameConstraints[$require] = null;
            }
        }

        return $loadNames;
    }
}

