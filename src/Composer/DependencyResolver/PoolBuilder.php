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

use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\PackageInterface;
use Composer\Package\Version\StabilityFilter;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PrePoolCreateEvent;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RootPackageRepository;
use Composer\Semver\CompilingMatcher;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\Semver\Intervals;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class PoolBuilder
{
    /**
     * @var int[]
     */
    private $acceptableStabilities;
    /**
     * @var int[]
     */
    private $stabilityFlags;
    /**
     * @psalm-var array<string, array<string, array{alias: string, alias_normalized: string}>>
     */
    private $rootAliases;
    /**
     * @psalm-var array<string, string>
     */
    private $rootReferences;
    /**
     * @var EventDispatcher
     */
    private $eventDispatcher;
    /**
     * @var IOInterface
     */
    private $io;
    /**
     * @psalm-var array<string, AliasPackage>
     */
    private $aliasMap = array();
    /**
     * @psalm-var array<string, LoadPackageOperation>
     */
    private $packagesToLoad = array();
    /**
     * @psalm-var array<string, ConstraintInterface>
     */
    private $loadedPackages = array();
    /**
     * @psalm-var array<string, array<int, LoadPackageOperation>>
     */
    private $loadPackageOperationsTrace = array();
    /**
     * @psalm-var array<int, array<string, array<string, PackageInterface>>>
     */
    private $loadedPerRepo = array();
    /**
     * @psalm-var Package[]
     */
    private $packages = array();
    /**
     * @psalm-var list<Package>
     */
    private $unacceptableFixedPackages = array();
    private $updateAllowList = array();
    private $skippedLoad = array();

    /**
     * Keeps a list of dependencies which are root requirements, and as such
     * have already their maximum required range loaded and can not be
     * extended by markPackageNameForLoading
     *
     * Packages get cleared from this list if they get unfixed as in that case
     * we need to actually load them
     */
    private $maxExtendedReqs = array();
    /**
     * @psalm-var array<string, bool>
     */
    private $updateAllowWarned = array();

    private $indexCounter = 0;

    /**
     * @param int[] $acceptableStabilities array of stability => BasePackage::STABILITY_* value
     * @psalm-param array<string, BasePackage::STABILITY_*> $acceptableStabilities
     * @param int[] $stabilityFlags an array of package name => BasePackage::STABILITY_* value
     * @psalm-param array<string, BasePackage::STABILITY_*> $stabilityFlags
     * @param array[] $rootAliases
     * @psalm-param array<string, array<string, array{alias: string, alias_normalized: string}>> $rootAliases
     * @param string[] $rootReferences an array of package name => source reference
     * @psalm-param array<string, string> $rootReferences
     */
    public function __construct(array $acceptableStabilities, array $stabilityFlags, array $rootAliases, array $rootReferences, IOInterface $io, EventDispatcher $eventDispatcher = null)
    {
        $this->acceptableStabilities = $acceptableStabilities;
        $this->stabilityFlags = $stabilityFlags;
        $this->rootAliases = $rootAliases;
        $this->rootReferences = $rootReferences;
        $this->eventDispatcher = $eventDispatcher;
        $this->io = $io;
    }

    public function buildPool(array $repositories, Request $request)
    {
        if ($request->getUpdateAllowList()) {
            $this->updateAllowList = $request->getUpdateAllowList();
            $this->warnAboutNonMatchingUpdateAllowList($request);

            foreach ($request->getLockedRepository()->getPackages() as $lockedPackage) {
                if (!$this->isUpdateAllowed($lockedPackage)) {
                    $request->fixPackage($lockedPackage);
                    $lockedName = $lockedPackage->getName();
                    // remember which packages we skipped loading remote content for in this partial update
                    $this->skippedLoad[$lockedName] = $lockedName;
                    foreach ($lockedPackage->getReplaces() as $link) {
                        $this->skippedLoad[$link->getTarget()] = $lockedName;
                    }
                }
            }
        }

        foreach ($request->getFixedPackages() as $package) {
            // using MatchAllConstraint here because fixed packages do not need to retrigger
            // loading any packages
            $this->loadedPackages[$package->getName()] = new MatchAllConstraint();

            // replace means conflict, so if a fixed package replaces a name, no need to load that one, packages would conflict anyways
            foreach ($package->getReplaces() as $link) {
                $this->loadedPackages[$link->getTarget()] = new MatchAllConstraint();
            }

            // TODO in how far can we do the above for conflicts? It's more tricky cause conflicts can be limited to
            // specific versions while replace is a conflict with all versions of the name

            if (
                $package->getRepository() instanceof RootPackageRepository
                || $package->getRepository() instanceof PlatformRepository
                || StabilityFilter::isPackageAcceptable($this->acceptableStabilities, $this->stabilityFlags, $package->getNames(), $package->getStability())
            ) {
                $this->loadPackage($request, $package, 0, false);
            } else {
                $this->unacceptableFixedPackages[] = $package;
            }
        }

        foreach ($request->getRequires() as $packageName => $constraint) {
            // fixed packages have already been added, so if a root require needs one of them, no need to do anything
            if (isset($this->loadedPackages[$packageName])) {
                continue;
            }

            $loadOperation = new LoadPackageOperation('root', 'root', $packageName, $constraint, 0);

            $this->maxExtendedReqs[$packageName] = true;
            $this->packagesToLoad[$packageName] = $loadOperation;
            $this->traceLoadPackageOperation($packageName, $loadOperation);
        }

        // clean up packagesToLoad for anything we manually marked loaded above
        foreach (array_keys($this->packagesToLoad) as $name) {
            if (isset($this->loadedPackages[$name])) {
                unset($this->packagesToLoad[$name]);
            }
        }

        while (!empty($this->packagesToLoad)) {
            $this->loadPackagesMarkedForLoading($request, $repositories);
        }

        foreach ($this->packages as $i => $package) {
            // we check all alias related packages at once, so no need to check individual aliases
            // isset also checks non-null value
            if (!$package instanceof AliasPackage) {
                $constraint = new Constraint('==', $package->getVersion());
                $aliasedPackages = array($i => $package);
                if (isset($this->aliasMap[spl_object_hash($package)])) {
                    $aliasedPackages += $this->aliasMap[spl_object_hash($package)];
                }

                $found = false;
                foreach ($aliasedPackages as $packageOrAlias) {
                    if (CompilingMatcher::match($constraint, Constraint::OP_EQ, $packageOrAlias->getVersion())) {
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

        $this->runOptimizer();

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
        $this->packagesToLoad = array();
        $this->loadedPackages = array();
        $this->loadedPerRepo = array();
        $this->loadPackageOperationsTrace = array();
        $this->packages = array();
        $this->unacceptableFixedPackages = array();
        $this->maxExtendedReqs = array();
        $this->skippedLoad = array();
        $this->indexCounter = 0;

        Intervals::clear();

        return $pool;
    }

    private function markPackageNameForLoading(Request $request, LoadPackageOperation $operation)
    {
        $name = $operation->getTarget();
        $constraint = $operation->getTargetConstraint();

        // Skip platform requires at this stage
        if (PlatformRepository::isPlatformPackage($name)) {
            return;
        }

        // Root require (which was not unfixed) already loaded the maximum range so no
        // need to check anything here
        if (isset($this->maxExtendedReqs[$name])) {
            return;
        }

        // Track the load package operation highest up the dependency tree per package
        $this->traceLoadPackageOperation($name, $operation);

        // Root requires can not be overruled by dependencies so there is no point in
        // extending the loaded constraint for those.
        // This is triggered when loading a root require which was fixed but got unfixed, then
        // we make sure that we load at most the intervals covered by the root constraint.
        $rootRequires = $request->getRequires();
        if (isset($rootRequires[$name]) && !Intervals::isSubsetOf($constraint, $rootRequires[$name])) {
            $constraint = $rootRequires[$name];
        }

        // Not yet loaded or already marked for a reload, override the existing constraint
        // (either it's a new one to load, or it has already been extended above)
        if (!isset($this->loadedPackages[$name])) {
            // Maybe it was already marked before but not loaded yet. In that case
            // we have to extend the constraint (we don't check if they are identical because
            // MultiConstraint::create() will optimize anyway)
            if (isset($this->packagesToLoad[$name])) {
                // Already marked for loading and this does not expand the constraint to be loaded, nothing to do
                if (Intervals::isSubsetOf($constraint, $this->packagesToLoad[$name]->getTargetConstraint())) {
                    return;
                }

                // extend the constraint to be loaded
                $constraint = Intervals::compactConstraint(MultiConstraint::create(array($this->packagesToLoad[$name]->getTargetConstraint(), $constraint), false));
            }

            $this->packagesToLoad[$name] = $operation->withTargetConstraint($constraint);
            return;
        }

        // No need to load this package with this constraint because it is
        // a subset of the constraint with which we have already loaded packages
        if (Intervals::isSubsetOf($constraint, $this->loadedPackages[$name])) {
            return;
        }

        // We have already loaded that package but not in the constraint that's
        // required. We extend the constraint and mark that package as not being loaded
        // yet so we get the required package versions
        $this->packagesToLoad[$name] = $operation->withTargetConstraint(
            Intervals::compactConstraint(MultiConstraint::create(array($this->loadedPackages[$name], $constraint), false))
        );
        unset($this->loadedPackages[$name]);
    }

    private function loadPackagesMarkedForLoading(Request $request, $repositories)
    {
        foreach ($this->packagesToLoad as $name => $operation) {
            $this->loadedPackages[$name] = $operation->getTargetConstraint();
        }

        $packageBatch = $this->packagesToLoad;
        $this->packagesToLoad = array();

        $packageBatchesByLevel = array();

        foreach ($packageBatch as $name => $operation) {
            if (!isset($packageBatchesByLevel[$operation->getLevelFoundOn()])) {
                $packageBatchesByLevel[$operation->getLevelFoundOn()] = array();
            }
            $packageBatchesByLevel[$operation->getLevelFoundOn()][$name] = $operation->getTargetConstraint();
        }

        foreach ($repositories as $repoIndex => $repository) {
            if (empty($packageBatch)) {
                break;
            }

            // these repos have their packages fixed if they need to be loaded so we
            // never need to load anything else from them
            if ($repository instanceof PlatformRepository || $repository === $request->getLockedRepository()) {
                continue;
            }

            foreach ($packageBatchesByLevel as $levelFoundOn => $packageBatch) {
                $nextLevel = $levelFoundOn + 1;
                $result = $repository->loadPackages($packageBatch, $this->acceptableStabilities, $this->stabilityFlags, isset($this->loadedPerRepo[$repoIndex]) ? $this->loadedPerRepo[$repoIndex] : array());

                foreach ($result['namesFound'] as $name) {
                    // avoid loading the same package again from other repositories once it has been found
                    unset($packageBatch[$name]);
                }
                foreach ($result['packages'] as $package) {
                    $this->loadedPerRepo[$repoIndex][$package->getName()][$package->getVersion()] = $package;
                    $this->loadPackage($request, $package, $nextLevel);
                }
            }
        }
    }

    private function loadPackage(Request $request, PackageInterface $package, $levelFoundOn, $propagateUpdate = true)
    {
        $index = $this->indexCounter++;
        $this->packages[$index] = $package;

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

        // if propogateUpdate is false we are loading a fixed package, root aliases do not apply as they are manually
        // loaded as separate packages in this case
        if ($propagateUpdate && isset($this->rootAliases[$name][$package->getVersion()])) {
            $alias = $this->rootAliases[$name][$package->getVersion()];
            if ($package instanceof AliasPackage) {
                $basePackage = $package->getAliasOf();
            } else {
                $basePackage = $package;
            }
            $aliasPackage = new AliasPackage($basePackage, $alias['alias_normalized'], $alias['alias']);
            $aliasPackage->setRootPackageAlias(true);

            $newIndex = $this->indexCounter++;
            $this->packages[$newIndex] = $aliasPackage;
            $this->aliasMap[spl_object_hash($aliasPackage->getAliasOf())][$newIndex] = $aliasPackage;
        }

        foreach ($package->getRequires() as $link) {
            $require = $link->getTarget();
            $linkConstraint = $link->getConstraint();

            if ($propagateUpdate) {
                // if this is a partial update with transitive dependencies we need to unfix the package we now know is a
                // dependency of another package which we are trying to update, and then attempt to load it again
                if ($request->getUpdateAllowTransitiveDependencies() && isset($this->skippedLoad[$require])) {
                    if ($request->getUpdateAllowTransitiveRootDependencies() || !$this->isRootRequire($request, $this->skippedLoad[$require])) {
                        $this->unfixPackage($request, $require);
                        $this->markPackageNameForLoading(
                            $request,
                            new LoadPackageOperation(
                                $package->getName(),
                                $package->getVersion(),
                                $require,
                                $linkConstraint,
                                $levelFoundOn
                            )
                        );
                    } elseif (!$request->getUpdateAllowTransitiveRootDependencies() && $this->isRootRequire($request, $require) && !isset($this->updateAllowWarned[$require])) {
                        $this->updateAllowWarned[$require] = true;
                        $this->io->writeError('<warning>Dependency "'.$require.'" is also a root requirement. Package has not been listed as an update argument, so keeping locked at old version. Use --with-all-dependencies to include root dependencies.</warning>');
                    }
                } else {
                    $this->markPackageNameForLoading(
                        $request,
                        new LoadPackageOperation(
                            $package->getName(),
                            $package->getVersion(),
                            $require,
                            $linkConstraint,
                            $levelFoundOn
                        )
                    );
                }
            } else {
                // We also need to load the requirements of a fixed package
                // unless it was skipped
                if (!isset($this->skippedLoad[$require])) {
                    $this->markPackageNameForLoading(
                        $request,
                        new LoadPackageOperation(
                            $package->getName(),
                            $package->getVersion(),
                            $require,
                            $linkConstraint,
                            $levelFoundOn
                        )
                    );
                }
            }
        }

        // if we're doing a partial update with deps we also need to unfix packages which are being replaced in case they
        // are currently locked and thus prevent this updateable package from being installable/updateable
        if ($propagateUpdate && $request->getUpdateAllowTransitiveDependencies()) {
            foreach ($package->getReplaces() as $link) {
                $replace = $link->getTarget();
                if (isset($this->loadedPackages[$replace]) && isset($this->skippedLoad[$replace])) {
                    if ($request->getUpdateAllowTransitiveRootDependencies() || !$this->isRootRequire($request, $this->skippedLoad[$replace])) {
                        $this->unfixPackage($request, $replace);
                        $this->markPackageNameForLoading(
                            $request,
                            new LoadPackageOperation(
                                $package->getName(),
                                $package->getVersion(),
                                $replace,
                                $link->getConstraint(),
                                $levelFoundOn
                            )
                        );
                    } elseif (!$request->getUpdateAllowTransitiveRootDependencies() && $this->isRootRequire($request, $replace) && !isset($this->updateAllowWarned[$replace])) {
                        $this->updateAllowWarned[$replace] = true;
                        $this->io->writeError('<warning>Dependency "'.$replace.'" is also a root requirement. Package has not been listed as an update argument, so keeping locked at old version. Use --with-all-dependencies to include root dependencies.</warning>');
                    }
                }
            }
        }
    }

    /**
     * Checks if a particular name is required directly in the request
     *
     * @return bool
     */
    private function isRootRequire(Request $request, $name)
    {
        $rootRequires = $request->getRequires();
        return isset($rootRequires[$name]);
    }

    /**
     * Checks whether the update allow list allows this package in the lock file to be updated
     * @return bool
     */
    private function isUpdateAllowed(PackageInterface $package)
    {
        foreach ($this->updateAllowList as $pattern => $void) {
            $patternRegexp = BasePackage::packageNameToRegexp($pattern);
            if (preg_match($patternRegexp, $package->getName())) {
                return true;
            }
        }

        return false;
    }

    private function warnAboutNonMatchingUpdateAllowList(Request $request)
    {
        foreach ($this->updateAllowList as $pattern => $void) {
            $patternRegexp = BasePackage::packageNameToRegexp($pattern);
            // update pattern matches a locked package? => all good
            foreach ($request->getLockedRepository()->getPackages() as $package) {
                if (preg_match($patternRegexp, $package->getName())) {
                    continue 2;
                }
            }
            // update pattern matches a root require? => all good, probably a new package
            foreach ($request->getRequires() as $packageName => $constraint) {
                if (preg_match($patternRegexp, $packageName)) {
                    continue 2;
                }
            }
            if (strpos($pattern, '*') !== false) {
                $this->io->writeError('<warning>Pattern "' . $pattern . '" listed for update does not match any locked packages.</warning>');
            } else {
                $this->io->writeError('<warning>Package "' . $pattern . '" listed for update is not locked.</warning>');
            }
        }
    }

    /**
     * Reverts the decision to use a fixed package from lock file if a partial update with transitive dependencies
     * found that this package actually needs to be updated
     */
    private function unfixPackage(Request $request, $name)
    {
        // remove locked package by this name which was already initialized
        foreach ($request->getLockedRepository()->getPackages() as $lockedPackage) {
            if (!($lockedPackage instanceof AliasPackage) && $lockedPackage->getName() === $name) {
                if (false !== $index = array_search($lockedPackage, $this->packages, true)) {
                    $request->unfixPackage($lockedPackage);
                    $this->removeLoadedPackage($request, $lockedPackage, $index);
                }
            }
        }

        if (
            // if we unfixed a replaced package name, we also need to unfix the replacer itself
            $this->skippedLoad[$name] !== $name
            // as long as it was not unfixed yet
            && isset($this->skippedLoad[$this->skippedLoad[$name]])
        ) {
            $this->unfixPackage($request, $this->skippedLoad[$name]);
        }

        unset($this->skippedLoad[$name]);
        unset($this->loadedPackages[$name]);
        unset($this->maxExtendedReqs[$name]);
    }

    private function removeLoadedPackage(Request $request, PackageInterface $package, $index)
    {
        unset($this->packages[$index]);
        if (isset($this->aliasMap[spl_object_hash($package)])) {
            foreach ($this->aliasMap[spl_object_hash($package)] as $aliasIndex => $aliasPackage) {
                $request->unfixPackage($aliasPackage);
                unset($this->packages[$aliasIndex]);
            }
            unset($this->aliasMap[spl_object_hash($package)]);
        }
    }

    /**
     * The packages array now contains all packages referenced somewhere along the dependency tree which might result
     * in thousands of packages.
     *
     * However, we have to understand that there's no way we can filter out certain packages during loading stage
     * because some packages found later on might replace previously found ones.
     *
     * So any further optimizations can only happen at this stage.
     *
     * How can we further optimize then?
     *
     * Think of a root dependency of your project. Let's say you require packageA in version ^4.0.
     * It's very likely that all the versions of packageA in <4.0 and >=5.0 have also been loaded because some
     * transitive dependency referenced packageA in older or newer versions.
     * Maybe we were lucky and no transitive dependency referenced 1.* versions so at least those ones are already gone.
     * However, that still leaves us with all 2.*, 3.* and >5.0 versions which we'll load even though our root requirement
     * already rules them out.
     * In practice, this doesn't happen because root dependency constraints are not expanded with later found references
     * but it maybe helps you to understand the logic behind the optimizer.
     *
     * The observation here:
     * The higher up in the dependency tree a package was required, the more likely it was controlled/requested by the user.
     * The highest level (level 0) being your root composer.json which is of course 100% controlled by the user and
     * contains 100% of what the user requests. Then, on level 1, we have all the direct dependencies of the root
     * dependencies, making those ones **extremely likely** to be requested by the user as well.
     * In other words, the higher up the dependency tree the requirer of a package was found, the higher the probability
     * that this constraint can be used as a hard requirement and does not need to be expanded on.
     */
    private function runOptimizer()
    {
        $total = \count($this->packages);

        $this->unlearnLoadPackageOperationTracesFromReplacedPackages();
        $this->evenLoadPackageOperationTraces();
        $this->applyOptimizations();

        $filtered = $total - \count($this->packages);

        if (0 === $filtered) {
            return;
        }

        $this->io->write(sprintf('<info>Pool builder found a total of %s package versions referenced in your dependency tree. The optimizer was able to filter out %s (%d%%) of them early!</info>',
            number_format($total),
            number_format($filtered),
            round(100/$total*$filtered)
        ));
    }

    private function applyOptimizations()
    {
        $enableRecursion = false;

        // Optimization 1: Remove all package versions that are not mentioned in any top level constraint
        $removedPackages = array();
        $highestDependencyTreeConstraints = $this->extractHighestDependencyTreeConstraints();

        foreach ($this->packages as $i => $package) {
            if (isset($highestDependencyTreeConstraints[$package->getName()]) && !$highestDependencyTreeConstraints[$package->getName()]->matches(new Constraint('==', $package->getVersion()))) {
                unset($this->packages[$i]);

                if (!isset($removedPackages[$package->getName()])) {
                    $removedPackages[$package->getName()] = array();
                }

                $removedPackages[$package->getName()][] = $package->getVersion();
                $enableRecursion = true;
            }
        }

        // Optimization 2: Unlearn load package operations learnt from packages that have now been removed and if it results
        // in 0 remaining load operations, we can remove that package completely
        $packagesToRemove = array();

        foreach ($this->loadPackageOperationsTrace as $targetPackageName => $operations) {
            foreach ($operations as $j => $operation) {
                if (isset($removedPackages[$operation->getSource()]) && \in_array($operation->getSourceVersion(), $removedPackages[$operation->getSource()], true)) {
                    unset($this->loadPackageOperationsTrace[$targetPackageName][$j]);

                    if (0 === \count($this->loadPackageOperationsTrace[$targetPackageName])) {
                        $packagesToRemove[] = $targetPackageName;
                    }
                }
            }
        }

        foreach ($this->packages as $i => $package) {
            if (\in_array($package->getName(), $packagesToRemove, true)) {
                unset($this->packages[$i]);
                $enableRecursion = true;
            }
        }

        // If we removed at least one package we'll run the optimizations recursively
        // to account for new things learnt along the way as we may have removed load package operation traces
        // resulting in a different set of $highestDependencyTreeConstraints
        if ($enableRecursion) {
            $this->applyOptimizations();
        }
    }

    /**
     * Before we extract the requirement highest up the dependency tree
     * we make sure we unlearn all the requirements from packages
     * that have been replaced which is done in this method.
     */
    private function unlearnLoadPackageOperationTracesFromReplacedPackages()
    {
        $replacedPackages = array();
        $replacedPackagesConstraints = array();

        // Collect all replacement constraints
        foreach ($this->packages as $package) {
            foreach ($package->getReplaces() as $link) {
                // Make sure we do not replace ourselves (if someone made a mistake and tagged it)
                // See e.g. https://github.com/BabDev/Pagerfanta/commit/fd00eb74632fecc0265327e9fe0eddc08c72b238#diff-b5d0ee8c97c7abd7e3fa29b9a27d1780
                // TODO: should that go into package itself?
                if ($package->getName() === $link->getTarget()) {
                    continue;
                }

                if (!isset($replacedPackagesConstraints[$link->getTarget()])) {
                    $replacedPackagesConstraints[$link->getTarget()] = array();
                }

                $replacedPackagesConstraints[$link->getTarget()][] = $link->getConstraint();
            }
        }

        // Collect replaced packages
        // Careful: Do NOT remove them from the packages.
        // This might seem like a good option (they've got replaced by other packages after all), however, they have
        // to end up in the pool so the solver can correctly resolve dependencies referring to replaced packages.
        foreach ($this->packages as $i => $package) {
            if (isset($replacedPackagesConstraints[$package->getName()])) {
                foreach($replacedPackagesConstraints[$package->getName()] as $replacementTarget => $replacementConstraint) {
                    if ($replacementConstraint->matches(new Constraint('==', $package->getVersion()))) {
                        $replacedPackages[] = $package;
                    }
                }
            }
        }

        // Unlearn all the traces from packages that have been replaced
        foreach ($replacedPackages as $replacedPackage) {
            foreach ($this->loadPackageOperationsTrace as $targetPackage => $operations) {
                foreach ($operations as $i => $operation) {
                    if ($operation->getSource() === $replacedPackage->getName() && $operation->getSourceVersion() === $replacedPackage->getVersion()) {
                        unset($this->loadPackageOperationsTrace[$targetPackage][$i]);
                    }
                }
            }
        }
    }

    /**
     * Let's say packageA in version 1.0.0 required packageB in ^2.0
     * and in later versions that dependency was removed. We are not
     * allowed to use ^2.0 as a hard constraint then so we need
     * to "even out" these ones by allowing any version of that
     * package found on this level.
     */
    private function evenLoadPackageOperationTraces()
    {
        $requiredPackageNamesOverAllSourceVersions = array();
        $requiredPackageNamesPerSourceVersion = array();

        // Collect all packages required in any version and per version
        foreach ($this->loadPackageOperationsTrace as $targetPackage => $operations) {
            foreach ($operations as $operation) {
                $requiredPackageNamesOverAllSourceVersions[$operation->getLevelFoundOn()][$operation->getSource()][$operation->getTarget()] = true;
                $requiredPackageNamesPerSourceVersion[$operation->getLevelFoundOn()][$operation->getSource()][$operation->getSourceVersion()][] = $operation->getTarget();
            }
        }

        // Even out
        foreach ($requiredPackageNamesPerSourceVersion as $levelFoundOn => $sources) {
            foreach ($sources as $source => $info) {
                foreach ($info as $sourceVersion => $targetPackages) {
                    if ($packagesToEvenOut = array_diff(array_keys($requiredPackageNamesOverAllSourceVersions[$levelFoundOn][$source]), $targetPackages)) {
                        foreach ($packagesToEvenOut as $packageName) {
                            $this->traceLoadPackageOperation($packageName, new LoadPackageOperation(
                                $source,
                                $sourceVersion,
                                $packageName,
                                new MatchAllConstraint(),
                                $levelFoundOn
                            ));
                        }
                    }
                }
            }
        }
    }

    /**
     * @return array<string, ConstraintInterface>
     */
    private function extractHighestDependencyTreeConstraints()
    {
        $constraints = array();

        foreach ($this->loadPackageOperationsTrace as $name => $operations) {

            $highestDependencyTreeConstraint = null;
            $currentLevel = null;

            foreach ($operations as $operation) {
                if (null === $currentLevel || $operation->getLevelFoundOn() < $currentLevel) {
                    $currentLevel = $operation->getLevelFoundOn();
                    $highestDependencyTreeConstraint = $operation->getTargetConstraint();
                    continue;
                }

                // If we're on the same level, we make sure we consider all of the constraints.
                // We consider them as disjunctive because if we would do conjunctive, we could
                // end up in having a conflict. That means we already know that these
                // packages are not compatible with each other but we don't know which one to
                // remove from the packages array. This is going to be the solver's task.
                // In other words, we have to load them all -> disjunctive.
                if ($operation->getLevelFoundOn() === $currentLevel) {
                    $highestDependencyTreeConstraint = Intervals::compactConstraint(
                        MultiConstraint::create(array(
                            $highestDependencyTreeConstraint,
                            $operation->getTargetConstraint()
                        ), false)
                    );
                }
            }

            if (null !== $highestDependencyTreeConstraint) {
                $constraints[$name] = $highestDependencyTreeConstraint;
            }
        }

        return $constraints;
    }

    /**
     * @param string $name
     * @param LoadPackageOperation $operation
     */
    private function traceLoadPackageOperation($name, $operation)
    {
        if (!isset($this->loadPackageOperationsTrace[$name])) {
            $this->loadPackageOperationsTrace[$name] = array();
        }

        $this->loadPackageOperationsTrace[$name][] = $operation;
    }
}

