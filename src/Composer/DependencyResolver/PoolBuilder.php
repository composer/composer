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
use Composer\Package\Version\StabilityFilter;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RootPackageRepository;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Plugin\PrePoolCreateEvent;
use Composer\Plugin\PluginEvents;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class PoolBuilder
{
    private $acceptableStabilities;
    private $stabilityFlags;
    private $rootAliases;
    private $rootReferences;
    private $eventDispatcher;

    private $aliasMap = array();
    private $nameConstraints = array();
    private $loadedNames = array();
    private $packages = array();
    private $unacceptableFixedPackages = array();

    public function __construct(array $acceptableStabilities, array $stabilityFlags, array $rootAliases, array $rootReferences, EventDispatcher $eventDispatcher = null)
    {
        $this->acceptableStabilities = $acceptableStabilities;
        $this->stabilityFlags = $stabilityFlags;
        $this->rootAliases = $rootAliases;
        $this->rootReferences = $rootReferences;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function buildPool(array $repositories, Request $request)
    {
        $loadNames = array();
        foreach ($request->getFixedPackages() as $package) {
            // TODO do we need to add this to nameConstraints at all?
            $this->nameConstraints[$package->getName()] = null;
            $this->loadedNames[$package->getName()] = true;

            // replace means conflict, so if a fixed package replaces a name, no need to load that one, packages would conflict anyways
            foreach ($package->getReplaces() as $link) {
                $this->nameConstraints[$package->getName()] = null;
                $this->loadedNames[$link->getTarget()] = true;
            }

            // TODO in how far can we do the above for conflicts? It's more tricky cause conflicts can be limited to
            // specific versions while replace is a conflict with all versions of the name

            if (
                $package->getRepository() instanceof RootPackageRepository
                || $package->getRepository() instanceof PlatformRepository
                || StabilityFilter::isPackageAcceptable($this->acceptableStabilities, $this->stabilityFlags, $package->getNames(), $package->getStability())
            ) {
                $loadNames += $this->loadPackage($request, $package);
            } else {
                $this->unacceptableFixedPackages[] = $package;
            }
        }

        foreach ($request->getRequires() as $packageName => $constraint) {
            // fixed packages have already been added, so if a root require needs one of them, no need to do anything
            if (isset($this->loadedNames[$packageName])) {
                continue;
            }

            $loadNames[$packageName] = $constraint;
            $this->nameConstraints[$packageName] = $constraint ? new MultiConstraint(array($constraint), false) : null;
        }

        // all the merged constraints from install requests + fixed packages can be applied
        // when loading package metadata already, as these are set in stone
        foreach ($this->nameConstraints as $package => $constraint) {
            if ($constraint !== null && array_key_exists($package, $loadNames)) {
                $loadNames[$package] = $constraint;
                unset($this->nameConstraints[$package]);
            }
        }

        // clean up loadNames for anything we manually marked loaded above
        foreach ($loadNames as $name => $void) {
            if (isset($this->loadedNames[$name])) {
                unset($loadNames[$name]);
            }
        }

        while (!empty($loadNames)) {
            foreach ($loadNames as $name => $void) {
                $this->loadedNames[$name] = true;
            }

            $newLoadNames = array();
            foreach ($repositories as $repository) {
                // these repos have their packages fixed if they need to be loaded so we
                // never need to load anything else from them
                if ($repository instanceof PlatformRepository || $repository === $request->getLockedRepository()) {
                    continue;
                }

                $result = $repository->loadPackages($loadNames, $this->acceptableStabilities, $this->stabilityFlags);

                foreach ($result['namesFound'] as $name) {
                    // avoid loading the same package again from other repositories once it has been found
                    unset($loadNames[$name]);
                }
                foreach ($result['packages'] as $package) {
                    $newLoadNames += $this->loadPackage($request, $package);
                }
            }

            $loadNames = $newLoadNames;
        }

        // filter packages according to all the require statements collected for each package
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
                    }
                }
            }
        }

        if ($this->eventDispatcher) {
            $prePoolCreateEvent = new PrePoolCreateEvent(
                PluginEvents::PRE_POOL_CREATE,
                $repositories,
                $request,
                $this->acceptableStabilities,
                $this->stabilityFlags,
                $this->rootAliases,
                $this->rootReferences,
                $this->packages,
                $this->unacceptableFixedPackages
            );
            $this->eventDispatcher->dispatch($prePoolCreateEvent->getName(), $prePoolCreateEvent);
            $this->packages = $prePoolCreateEvent->getPackages();
            $this->unacceptableFixedPackages = $prePoolCreateEvent->getUnacceptableFixedPackages();
        }

        $pool = new Pool($this->packages, $this->unacceptableFixedPackages);

        $this->aliasMap = array();
        $this->nameConstraints = array();
        $this->loadedNames = array();
        $this->packages = array();
        $this->unacceptableFixedPackages = array();

        return $pool;
    }

    private function loadPackage(Request $request, PackageInterface $package)
    {
        $index = count($this->packages);
        $this->packages[] = $package;

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
                $package->setSourceDistReferences($this->rootReferences[$name]);
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

            $this->packages[] = $aliasPackage;
            $this->aliasMap[spl_object_hash($aliasPackage->getAliasOf())][$index+1] = $aliasPackage;
        }

        $loadNames = array();
        foreach ($package->getRequires() as $link) {
            $require = $link->getTarget();
            if (!isset($this->loadedNames[$require])) {
                $loadNames[$require] = null;
            }
            if ($linkConstraint = $link->getConstraint()) {
                // TODO check if linkConstraint is EmptyConstraint then set to null as well?

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
}

