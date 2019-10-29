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
use Composer\Package\Package;
use Composer\Package\PackageInterface;
use Composer\Repository\AsyncRepositoryInterface;
use Composer\Repository\ComposerRepository;
use Composer\Repository\InstalledRepositoryInterface;
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
    private $rootReferences;

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

    public function buildPool(array $repositories, array $rootAliases, array $rootReferences, Request $request)
    {
        $pool = new Pool($this->filterRequires);
        $this->rootAliases = $rootAliases;
        $this->rootReferences = $rootReferences;

        // TODO do we really want the request here? kind of want a root requirements thingy instead
        $loadNames = array();
        foreach ($request->getFixedPackages() as $package) {
            // TODO can actually use very specific constraint
            $loadNames[$package->getName()] = null;
        }

        foreach ($request->getJobs() as $job) {
            switch ($job['cmd']) {
                case 'install':
                    // TODO currently lock above is always NULL if we adjust that, this needs to merge constraints
                    // TODO does it really make sense that we can have install requests for the same package that is actively locked with non-matching constraints?
                    // also see the solver-problems.test test case
                    $constraint = array_key_exists($job['packageName'], $loadNames) ? null : $job['constraint'];
                    $loadNames[$job['packageName']] = $constraint;
                    $this->nameConstraints[$job['packageName']] = $constraint ? new MultiConstraint(array($constraint), false) : null;
                    break;
            }
        }

        // packages from the locked repository only get loaded if they are explicitly fixed
        foreach ($repositories as $key => $repository) {
            if ($repository === $request->getLockedRepository()) {
                foreach ($repository->getPackages() as $lockedPackage) {
                    foreach ($request->getFixedPackages() as $package) {
                        if ($package === $lockedPackage) {
                            $loadNames += $this->loadPackage($request, $package, $key);
                        }
                    }
                }
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
                if ($repository instanceof PlatformRepository || $repository instanceof InstalledRepositoryInterface || $repository === $request->getLockedRepository()) {
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
                        $newLoadNames += $this->loadPackage($request, $package, $key);
                    }
                }
            }

            $loadNames = $newLoadNames;
        }

        foreach ($this->packages as $i => $package) {
            // we check all alias related packages at once, so no need to check individual aliases
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
                    $this->loadPackage($request, $package, $key);
                }
            }
        }

        $pool->setPackages($this->packages, $this->priorities);

        unset($this->aliasMap);
        unset($this->loadedNames);
        unset($this->nameConstraints);

        return $pool;
    }

    private function loadPackage(Request $request, PackageInterface $package, $repoIndex)
    {
        $index = count($this->packages);
        $this->packages[] = $package;
        $this->priorities[] = -$repoIndex;

        if ($package instanceof AliasPackage) {
            $this->aliasMap[spl_object_hash($package->getAliasOf())][$index] = $package;
        }

        $name = $package->getName();

        // we're simply setting the root references on all versions for a name here and rely on the solver to pick the
        // right version. It'd be more work to figure out which versions and which aliases of those versions this may
        // apply to
        if (isset($this->rootReferences[$name])) {
            // do not modify the references on already locked packages
            if (!$request->isFixedPackage($package)) {
                $this->setReferences($package, $this->rootReferences[$name]);
            }
        }

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
            if ($linkConstraint = $link->getConstraint()) {
                if (!array_key_exists($require, $this->nameConstraints)) {
                    $this->nameConstraints[$require] = new MultiConstraint(array($linkConstraint), false);
                } elseif ($this->nameConstraints[$require]) {
                    // TODO addConstraint function?
                    $this->nameConstraints[$require] = new MultiConstraint(array_merge(array($linkConstraint), $this->nameConstraints[$require]->getConstraints()), false);
                }
                // else it is null and should stay null
            } else {
                $this->nameConstraints[$require] = null;
            }
        }

        return $loadNames;
    }

    private function setReferences(Package $package, $reference)
    {
        $package->setSourceReference($reference);

        // only bitbucket, github and gitlab have auto generated dist URLs that easily allow replacing the reference in the dist URL
        // TODO generalize this a bit for self-managed/on-prem versions? Some kind of replace token in dist urls which allow this?
        if (preg_match('{^https?://(?:(?:www\.)?bitbucket\.org|(api\.)?github\.com|(?:www\.)?gitlab\.com)/}i', $package->getDistUrl())) {
            $package->setDistReference($reference);
            $package->setDistUrl(preg_replace('{(?<=/|sha=)[a-f0-9]{40}(?=/|$)}i', $reference, $package->getDistUrl()));
        } elseif ($package->getDistReference()) { // update the dist reference if there was one, but if none was provided ignore it
            $package->setDistReference($reference);
        }
    }
}

