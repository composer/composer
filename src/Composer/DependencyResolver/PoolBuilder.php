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

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class PoolBuilder
{
    private $isPackageAcceptableCallable;
    private $filterRequires;
    private $rootAliases;

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
        $this->pool = new Pool($this->filterRequires);
        $this->rootAliases = $rootAliases;

        // TODO do we really want the request here? kind of want a root requirements thingy instead
        $loadNames = array();
        foreach ($request->getJobs() as $job) {
            switch ($job['cmd']) {
                case 'install':
                    $loadNames[$job['packageName']] = $job['constraint'];
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

        foreach ($repositories as $key => $repository) {
            if ($repository instanceof PlatformRepository ||
                $repository instanceof InstalledRepositoryInterface) {
                foreach ($repository->getPackages() as $package) {
                    $this->loadPackage($package, $key);
                }
            }
        }

        $this->pool->setPackages($this->packages, $this->priorities);

        return $this->pool;
    }

    private function loadPackage(PackageInterface $package, $repoIndex)
    {
        $this->packages[] = $package;
        $this->priorities[] = -$repoIndex;

        // handle root package aliases
        $name = $package->getName();
        if (isset($this->rootAliases[$name][$package->getVersion()])) {
            $alias = $this->rootAliases[$name][$package->getVersion()];
            if ($package instanceof AliasPackage) {
                $package = $package->getAliasOf();
            }
            $aliasPackage = new AliasPackage($package, $alias['alias_normalized'], $alias['alias']);
            $aliasPackage->setRootPackageAlias(true);

            $package->getRepository()->addPackage($aliasPackage); // TODO do we need this?
            $this->packages[] = $aliasPackage;
        }

        $loadNames = array();
        foreach ($package->getRequires() as $link) {
            $require = $link->getTarget();
            if (!isset($this->loadedNames[$require])) {
                $loadNames[$require] = null;
            }
        }

        return $loadNames;
    }
}

