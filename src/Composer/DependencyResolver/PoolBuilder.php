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

use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\CompleteAliasPackage;
use Composer\Package\CompletePackage;
use Composer\Package\PackageInterface;
use Composer\Package\Version\StabilityFilter;
use Composer\Pcre\Preg;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PrePoolCreateEvent;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
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
    private const LOAD_BATCH_SIZE = 50;

    /**
     * @var int[]
     * @phpstan-var array<key-of<BasePackage::STABILITIES>, BasePackage::STABILITY_*>
     */
    private $acceptableStabilities;
    /**
     * @var int[]
     * @phpstan-var array<string, BasePackage::STABILITY_*>
     */
    private $stabilityFlags;
    /**
     * @var array[]
     * @phpstan-var array<string, array<string, array{alias: string, alias_normalized: string}>>
     */
    private $rootAliases;
    /**
     * @var string[]
     * @phpstan-var array<string, string>
     */
    private $rootReferences;
    /**
     * @var array<string, ConstraintInterface>
     */
    private $temporaryConstraints;
    /**
     * @var ?EventDispatcher
     */
    private $eventDispatcher;
    /**
     * @var PoolOptimizer|null
     */
    private $poolOptimizer;
    /**
     * @var IOInterface
     */
    private $io;
    /**
     * @var array[]
     * @phpstan-var array<string, AliasPackage[]>
     */
    private $aliasMap = [];
    /**
     * @var ConstraintInterface[]
     * @phpstan-var array<string, ConstraintInterface>
     */
    private $packagesToLoad = [];
    /**
     * @var ConstraintInterface[]
     * @phpstan-var array<string, ConstraintInterface>
     */
    private $loadedPackages = [];
    /**
     * @var array[]
     * @phpstan-var array<int, array<string, array<string, PackageInterface>>>
     */
    private $loadedPerRepo = [];
    /**
     * @var array<int, BasePackage>
     */
    private $packages = [];
    /**
     * @var BasePackage[]
     */
    private $unacceptableFixedOrLockedPackages = [];
    /** @var array<string> */
    private $updateAllowList = [];
    /** @var array<string, array<PackageInterface>> */
    private $skippedLoad = [];
    /** @var list<string> */
    private $ignoredTypes = [];
    /** @var list<string>|null */
    private $allowedTypes = null;

    /**
     * If provided, only these package names are loaded
     *
     * This is a special-use functionality of the Request class to optimize the pool creation process
     * when only a minimal subset of packages is needed and we do not need their dependencies.
     *
     * @var array<string, int>|null
     */
    private $restrictedPackagesList = null;

    /**
     * Keeps a list of dependencies which are locked but were auto-unlocked as they are path repositories
     *
     * This half-unlocked state means the package itself will update but the UPDATE_LISTED_WITH_TRANSITIVE_DEPS*
     * flags will not apply until the package really gets unlocked in some other way than being a path repo
     *
     * @var array<string, true>
     */
    private $pathRepoUnlocked = [];

    /**
     * Keeps a list of dependencies which are root requirements, and as such
     * have already their maximum required range loaded and can not be
     * extended by markPackageNameForLoading
     *
     * Packages get cleared from this list if they get unlocked as in that case
     * we need to actually load them
     *
     * @var array<string, true>
     */
    private $maxExtendedReqs = [];
    /**
     * @var array
     * @phpstan-var array<string, bool>
     */
    private $updateAllowWarned = [];

    /** @var int */
    private $indexCounter = 0;

    /** @var ?SecurityAdvisoryPoolFilter */
    private $securityAdvisoryPoolFilter;

    /** @var ?ReleaseAgePoolFilter */
    private $releaseAgePoolFilter;

    /**
     * @param int[] $acceptableStabilities array of stability => BasePackage::STABILITY_* value
     * @phpstan-param array<key-of<BasePackage::STABILITIES>, BasePackage::STABILITY_*> $acceptableStabilities
     * @param int[] $stabilityFlags an array of package name => BasePackage::STABILITY_* value
     * @phpstan-param array<string, BasePackage::STABILITY_*> $stabilityFlags
     * @param array[] $rootAliases
     * @phpstan-param array<string, array<string, array{alias: string, alias_normalized: string}>> $rootAliases
     * @param string[] $rootReferences an array of package name => source reference
     * @phpstan-param array<string, string> $rootReferences
     * @param array<string, ConstraintInterface> $temporaryConstraints Runtime temporary constraints that will be used to filter packages
     */
    public function __construct(array $acceptableStabilities, array $stabilityFlags, array $rootAliases, array $rootReferences, IOInterface $io, ?EventDispatcher $eventDispatcher = null, ?PoolOptimizer $poolOptimizer = null, array $temporaryConstraints = [], ?SecurityAdvisoryPoolFilter $securityAdvisoryPoolFilter = null, ?ReleaseAgePoolFilter $releaseAgePoolFilter = null)
    {
        $this->acceptableStabilities = $acceptableStabilities;
        $this->stabilityFlags = $stabilityFlags;
        $this->rootAliases = $rootAliases;
        $this->rootReferences = $rootReferences;
        $this->eventDispatcher = $eventDispatcher;
        $this->poolOptimizer = $poolOptimizer;
        $this->io = $io;
        $this->temporaryConstraints = $temporaryConstraints;
        $this->securityAdvisoryPoolFilter = $securityAdvisoryPoolFilter;
        $this->releaseAgePoolFilter = $releaseAgePoolFilter;
    }

    /**
     * Packages of those types are ignored
     *
     * @param list<string> $types
     */
    public function setIgnoredTypes(array $types): void
    {
        $this->ignoredTypes = $types;
    }

    /**
     * Only packages of those types are allowed if set to non-null
     *
     * @param list<string>|null $types
     */
    public function setAllowedTypes(?array $types): void
    {
        $this->allowedTypes = $types;
    }

    /**
     * @param RepositoryInterface[] $repositories
     */
    public function buildPool(array $repositories, Request $request): Pool
    {
        $this->restrictedPackagesList = $request->getRestrictedPackages() !== null ? array_flip($request->getRestrictedPackages()) : null;

        if (\count($request->getUpdateAllowList()) > 0) {
            $this->updateAllowList = $request->getUpdateAllowList();
            $this->warnAboutNonMatchingUpdateAllowList($request);

            if (null === $request->getLockedRepository()) {
                throw new \LogicException('No lock repo present and yet a partial update was requested.');
            }

            foreach ($request->getLockedRepository()->getPackages() as $lockedPackage) {
                if (!$this->isUpdateAllowed($lockedPackage)) {
                    // remember which packages we skipped loading remote content for in this partial update
                    $this->skippedLoad[$lockedPackage->getName()][] = $lockedPackage;
                    foreach ($lockedPackage->getReplaces() as $link) {
                        $this->skippedLoad[$link->getTarget()][] = $lockedPackage;
                    }

                    // Path repo packages are never loaded from lock, to force them to always remain in sync
                    // unless symlinking is disabled in which case we probably should rather treat them like
                    // regular packages. We mark them specially so they can be reloaded fully including update propagation
                    // if they do get unlocked, but by default they are unlocked without update propagation.
                    if ($lockedPackage->getDistType() === 'path') {
                        $transportOptions = $lockedPackage->getTransportOptions();
                        if (!isset($transportOptions['symlink']) || $transportOptions['symlink'] !== false) {
                            $this->pathRepoUnlocked[$lockedPackage->getName()] = true;
                            continue;
                        }
                    }

                    $request->lockPackage($lockedPackage);
                }
            }
        }

        foreach ($request->getFixedOrLockedPackages() as $package) {
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
                $this->loadPackage($request, $repositories, $package, false);
            } else {
                $this->unacceptableFixedOrLockedPackages[] = $package;
            }
        }

        foreach ($request->getRequires() as $packageName => $constraint) {
            // fixed and locked packages have already been added, so if a root require needs one of them, no need to do anything
            if (isset($this->loadedPackages[$packageName])) {
                continue;
            }

            $this->packagesToLoad[$packageName] = $constraint;
            $this->maxExtendedReqs[$packageName] = true;
        }

        // clean up packagesToLoad for anything we manually marked loaded above
        foreach ($this->packagesToLoad as $name => $constraint) {
            if (isset($this->loadedPackages[$name])) {
                unset($this->packagesToLoad[$name]);
            }
        }

        while (\count($this->packagesToLoad) > 0) {
            $this->loadPackagesMarkedForLoading($request, $repositories);
        }

        if (\count($this->temporaryConstraints) > 0) {
            foreach ($this->packages as $i => $package) {
                // we check all alias related packages at once, so no need to check individual aliases
                if ($package instanceof AliasPackage) {
                    continue;
                }

                foreach ($package->getNames() as $packageName) {
                    if (!isset($this->temporaryConstraints[$packageName])) {
                        continue;
                    }

                    $constraint = $this->temporaryConstraints[$packageName];
                    $packageAndAliases = [$i => $package];
                    if (isset($this->aliasMap[spl_object_hash($package)])) {
                        $packageAndAliases += $this->aliasMap[spl_object_hash($package)];
                    }

                    $found = false;
                    foreach ($packageAndAliases as $packageOrAlias) {
                        if (CompilingMatcher::match($constraint, Constraint::OP_EQ, $packageOrAlias->getVersion())) {
                            $found = true;
                        }
                    }

                    if (!$found) {
                        foreach ($packageAndAliases as $index => $packageOrAlias) {
                            unset($this->packages[$index]);
                        }
                    }
                }
            }
        }

        if ($this->eventDispatcher !== null) {
            $prePoolCreateEvent = new PrePoolCreateEvent(
                PluginEvents::PRE_POOL_CREATE,
                $repositories,
                $request,
                $this->acceptableStabilities,
                $this->stabilityFlags,
                $this->rootAliases,
                $this->rootReferences,
                $this->packages,
                $this->unacceptableFixedOrLockedPackages
            );
            $this->eventDispatcher->dispatch($prePoolCreateEvent->getName(), $prePoolCreateEvent);
            $this->packages = $prePoolCreateEvent->getPackages();
            $this->unacceptableFixedOrLockedPackages = $prePoolCreateEvent->getUnacceptableFixedPackages();
        }

        $pool = new Pool($this->packages, $this->unacceptableFixedOrLockedPackages);

        $this->aliasMap = [];
        $this->packagesToLoad = [];
        $this->loadedPackages = [];
        $this->loadedPerRepo = [];
        $this->packages = [];
        $this->unacceptableFixedOrLockedPackages = [];
        $this->maxExtendedReqs = [];
        $this->skippedLoad = [];
        $this->indexCounter = 0;

        $this->io->debug('Built pool.');

        // filter vulnerable packages before optimizing the pool otherwise we may end up with inconsistent state where the optimizer took away versions
        // that were not vulnerable and now suddenly the vulnerable ones are removed and we are missing some versions to make it solvable
        $pool = $this->runSecurityAdvisoryFilter($pool, $repositories, $request);
        // filter packages that don't meet the minimum-release-age requirement
        $pool = $this->runReleaseAgeFilter($pool, $repositories, $request);
        $pool = $this->runOptimizer($request, $pool);

        Intervals::clear();

        return $pool;
    }

    private function markPackageNameForLoading(Request $request, string $name, ConstraintInterface $constraint): void
    {
        // Skip platform requires at this stage
        if (PlatformRepository::isPlatformPackage($name)) {
            return;
        }

        // Root require (which was not unlocked) already loaded the maximum range so no
        // need to check anything here
        if (isset($this->maxExtendedReqs[$name])) {
            return;
        }

        // Root requires can not be overruled by dependencies so there is no point in
        // extending the loaded constraint for those.
        // This is triggered when loading a root require which was locked but got unlocked, then
        // we make sure that we load at most the intervals covered by the root constraint.
        $rootRequires = $request->getRequires();
        if (isset($rootRequires[$name]) && !Intervals::isSubsetOf($constraint, $rootRequires[$name])) {
            $constraint = $rootRequires[$name];
        }

        // Not yet loaded or already marked for a reload, set the constraint to be loaded
        if (!isset($this->loadedPackages[$name])) {
            // Maybe it was already marked before but not loaded yet. In that case
            // we have to extend the constraint (we don't check if they are identical because
            // MultiConstraint::create() will optimize anyway)
            if (isset($this->packagesToLoad[$name])) {
                // Already marked for loading and this does not expand the constraint to be loaded, nothing to do
                if (Intervals::isSubsetOf($constraint, $this->packagesToLoad[$name])) {
                    return;
                }

                // extend the constraint to be loaded
                $constraint = Intervals::compactConstraint(MultiConstraint::create([$this->packagesToLoad[$name], $constraint], false));
            }

            $this->packagesToLoad[$name] = $constraint;

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
        $this->packagesToLoad[$name] = Intervals::compactConstraint(MultiConstraint::create([$this->loadedPackages[$name], $constraint], false));
        unset($this->loadedPackages[$name]);
    }

    /**
     * @param RepositoryInterface[] $repositories
     */
    private function loadPackagesMarkedForLoading(Request $request, array $repositories): void
    {
        foreach ($this->packagesToLoad as $name => $constraint) {
            if ($this->restrictedPackagesList !== null && !isset($this->restrictedPackagesList[$name])) {
                unset($this->packagesToLoad[$name]);
                continue;
            }
            $this->loadedPackages[$name] = $constraint;
        }

        // Load packages in chunks of 50 to prevent memory usage build-up due to caches of all sorts
        $packageBatches = array_chunk($this->packagesToLoad, self::LOAD_BATCH_SIZE, true);
        $this->packagesToLoad = [];

        foreach ($repositories as $repoIndex => $repository) {
            // these repos have their packages fixed or locked if they need to be loaded so we
            // never need to load anything else from them
            if ($repository instanceof PlatformRepository || $repository === $request->getLockedRepository()) {
                continue;
            }

            if (0 === \count($packageBatches)) {
                break;
            }

            foreach ($packageBatches as $batchIndex => $packageBatch) {
                $result = $repository->loadPackages($packageBatch, $this->acceptableStabilities, $this->stabilityFlags, $this->loadedPerRepo[$repoIndex] ?? []);

                foreach ($result['namesFound'] as $name) {
                    // avoid loading the same package again from other repositories once it has been found
                    unset($packageBatches[$batchIndex][$name]);
                }
                foreach ($result['packages'] as $package) {
                    $this->loadedPerRepo[$repoIndex][$package->getName()][$package->getVersion()] = $package;

                    if (in_array($package->getType(), $this->ignoredTypes, true) || ($this->allowedTypes !== null && !in_array($package->getType(), $this->allowedTypes, true))) {
                        continue;
                    }
                    $this->loadPackage($request, $repositories, $package, !isset($this->pathRepoUnlocked[$package->getName()]));
                }
            }

            $packageBatches = array_chunk(array_merge(...$packageBatches), self::LOAD_BATCH_SIZE, true);
        }
    }

    /**
     * @param RepositoryInterface[] $repositories
     */
    private function loadPackage(Request $request, array $repositories, BasePackage $package, bool $propagateUpdate): void
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
            // do not modify the references on already locked or fixed packages
            if (!$request->isLockedPackage($package) && !$request->isFixedPackage($package)) {
                $package->setSourceDistReferences($this->rootReferences[$name]);
            }
        }

        // if propagateUpdate is false we are loading a fixed or locked package, root aliases do not apply as they are
        // manually loaded as separate packages in this case
        //
        // packages in pathRepoUnlocked however need to also load root aliases, they have propagateUpdate set to
        // false because their deps should not be unlocked, but that is irrelevant for root aliases
        if (($propagateUpdate || isset($this->pathRepoUnlocked[$package->getName()])) && isset($this->rootAliases[$name][$package->getVersion()])) {
            $alias = $this->rootAliases[$name][$package->getVersion()];
            if ($package instanceof AliasPackage) {
                $basePackage = $package->getAliasOf();
            } else {
                $basePackage = $package;
            }
            if ($basePackage instanceof CompletePackage) {
                $aliasPackage = new CompleteAliasPackage($basePackage, $alias['alias_normalized'], $alias['alias']);
            } else {
                $aliasPackage = new AliasPackage($basePackage, $alias['alias_normalized'], $alias['alias']);
            }
            $aliasPackage->setRootPackageAlias(true);

            $newIndex = $this->indexCounter++;
            $this->packages[$newIndex] = $aliasPackage;
            $this->aliasMap[spl_object_hash($aliasPackage->getAliasOf())][$newIndex] = $aliasPackage;
        }

        foreach ($package->getRequires() as $link) {
            $require = $link->getTarget();
            $linkConstraint = $link->getConstraint();

            // if the required package is loaded as a locked package only and hasn't had its deps analyzed
            if (isset($this->skippedLoad[$require])) {
                // if we're doing a full update or this is a partial update with transitive deps and we're currently
                // looking at a package which needs to be updated we need to unlock the package we now know is a
                // dependency of another package which we are trying to update, and then attempt to load it again
                if ($propagateUpdate && $request->getUpdateAllowTransitiveDependencies()) {
                    $skippedRootRequires = $this->getSkippedRootRequires($request, $require);

                    if ($request->getUpdateAllowTransitiveRootDependencies() || 0 === \count($skippedRootRequires)) {
                        $this->unlockPackage($request, $repositories, $require);
                        $this->markPackageNameForLoading($request, $require, $linkConstraint);
                    } else {
                        foreach ($skippedRootRequires as $rootRequire) {
                            if (!isset($this->updateAllowWarned[$rootRequire])) {
                                $this->updateAllowWarned[$rootRequire] = true;
                                $this->io->writeError('<warning>Dependency '.$rootRequire.' is also a root requirement. Package has not been listed as an update argument, so keeping locked at old version. Use --with-all-dependencies (-W) to include root dependencies.</warning>');
                            }
                        }
                    }
                } elseif (isset($this->pathRepoUnlocked[$require]) && !isset($this->loadedPackages[$require])) {
                    // if doing a partial update and a package depends on a path-repo-unlocked package which is not referenced by the root, we need to ensure it gets loaded as it was not loaded by the request's root requirements
                    // and would not be loaded above if update propagation is not allowed (which happens if the requirer is itself a path-repo-unlocked package) or if transitive deps are not allowed to be unlocked
                    $this->markPackageNameForLoading($request, $require, $linkConstraint);
                }
            } else {
                $this->markPackageNameForLoading($request, $require, $linkConstraint);
            }
        }

        // if we're doing a partial update with deps we also need to unlock packages which are being replaced in case
        // they are currently locked and thus prevent this updateable package from being installable/updateable
        if ($propagateUpdate && $request->getUpdateAllowTransitiveDependencies()) {
            foreach ($package->getReplaces() as $link) {
                $replace = $link->getTarget();
                if (isset($this->loadedPackages[$replace], $this->skippedLoad[$replace])) {
                    $skippedRootRequires = $this->getSkippedRootRequires($request, $replace);

                    if ($request->getUpdateAllowTransitiveRootDependencies() || 0 === \count($skippedRootRequires)) {
                        $this->unlockPackage($request, $repositories, $replace);
                        // the replaced package only needs to be loaded if something else requires it
                        $this->markPackageNameForLoadingIfRequired($request, $replace);
                    } else {
                        foreach ($skippedRootRequires as $rootRequire) {
                            if (!isset($this->updateAllowWarned[$rootRequire])) {
                                $this->updateAllowWarned[$rootRequire] = true;
                                $this->io->writeError('<warning>Dependency '.$rootRequire.' is also a root requirement. Package has not been listed as an update argument, so keeping locked at old version. Use --with-all-dependencies (-W) to include root dependencies.</warning>');
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Checks if a particular name is required directly in the request
     *
     * @param string $name packageName
     */
    private function isRootRequire(Request $request, string $name): bool
    {
        $rootRequires = $request->getRequires();

        return isset($rootRequires[$name]);
    }

    /**
     * @return string[]
     */
    private function getSkippedRootRequires(Request $request, string $name): array
    {
        if (!isset($this->skippedLoad[$name])) {
            return [];
        }

        $rootRequires = $request->getRequires();
        $matches = [];

        if (isset($rootRequires[$name])) {
            return array_map(static function (PackageInterface $package) use ($name): string {
                if ($name !== $package->getName()) {
                    return $package->getName() .' (via replace of '.$name.')';
                }

                return $package->getName();
            }, $this->skippedLoad[$name]);
        }

        foreach ($this->skippedLoad[$name] as $packageOrReplacer) {
            if (isset($rootRequires[$packageOrReplacer->getName()])) {
                $matches[] = $packageOrReplacer->getName();
            }
            foreach ($packageOrReplacer->getReplaces() as $link) {
                if (isset($rootRequires[$link->getTarget()])) {
                    if ($name !== $packageOrReplacer->getName()) {
                        $matches[] = $packageOrReplacer->getName() .' (via replace of '.$name.')';
                    } else {
                        $matches[] = $packageOrReplacer->getName();
                    }
                    break;
                }
            }
        }

        return $matches;
    }

    /**
     * Checks whether the update allow list allows this package in the lock file to be updated
     */
    private function isUpdateAllowed(BasePackage $package): bool
    {
        foreach ($this->updateAllowList as $pattern) {
            $patternRegexp = BasePackage::packageNameToRegexp($pattern);
            if (Preg::isMatch($patternRegexp, $package->getName())) {
                return true;
            }
        }

        return false;
    }

    private function warnAboutNonMatchingUpdateAllowList(Request $request): void
    {
        if (null === $request->getLockedRepository()) {
            throw new \LogicException('No lock repo present and yet a partial update was requested.');
        }

        foreach ($this->updateAllowList as $pattern) {
            $matchedPlatformPackage = false;

            $patternRegexp = BasePackage::packageNameToRegexp($pattern);
            // update pattern matches a locked package? => all good
            foreach ($request->getLockedRepository()->getPackages() as $package) {
                if (Preg::isMatch($patternRegexp, $package->getName())) {
                    continue 2;
                }
            }
            // update pattern matches a root require? => all good, probably a new package
            foreach ($request->getRequires() as $packageName => $constraint) {
                if (Preg::isMatch($patternRegexp, $packageName)) {
                    if (PlatformRepository::isPlatformPackage($packageName)) {
                        $matchedPlatformPackage = true;
                        continue;
                    }
                    continue 2;
                }
            }
            if ($matchedPlatformPackage) {
                $this->io->writeError('<warning>Pattern "' . $pattern . '" listed for update matches platform packages, but these cannot be updated by Composer.</warning>');
            } elseif (strpos($pattern, '*') !== false) {
                $this->io->writeError('<warning>Pattern "' . $pattern . '" listed for update does not match any locked packages.</warning>');
            } else {
                $this->io->writeError('<warning>Package "' . $pattern . '" listed for update is not locked.</warning>');
            }
        }
    }

    /**
     * Reverts the decision to use a locked package if a partial update with transitive dependencies
     * found that this package actually needs to be updated
     *
     * @param RepositoryInterface[] $repositories
     */
    private function unlockPackage(Request $request, array $repositories, string $name): void
    {
        foreach ($this->skippedLoad[$name] as $packageOrReplacer) {
            // if we unfixed a replaced package name, we also need to unfix the replacer itself
            // as long as it was not unfixed yet
            if ($packageOrReplacer->getName() !== $name && isset($this->skippedLoad[$packageOrReplacer->getName()])) {
                $replacerName = $packageOrReplacer->getName();
                if ($request->getUpdateAllowTransitiveRootDependencies() || (!$this->isRootRequire($request, $name) && !$this->isRootRequire($request, $replacerName))) {
                    $this->unlockPackage($request, $repositories, $replacerName);

                    if ($this->isRootRequire($request, $replacerName)) {
                        $this->markPackageNameForLoading($request, $replacerName, new MatchAllConstraint);
                    } else {
                        foreach ($this->packages as $loadedPackage) {
                            $requires = $loadedPackage->getRequires();
                            if (isset($requires[$replacerName])) {
                                $this->markPackageNameForLoading($request, $replacerName, $requires[$replacerName]->getConstraint());
                            }
                        }
                    }
                }
            }
        }

        if (isset($this->pathRepoUnlocked[$name])) {
            foreach ($this->packages as $index => $package) {
                if ($package->getName() === $name) {
                    $this->removeLoadedPackage($request, $repositories, $package, $index);
                }
            }
        }

        unset($this->skippedLoad[$name], $this->loadedPackages[$name], $this->maxExtendedReqs[$name], $this->pathRepoUnlocked[$name]);

        // remove locked package by this name which was already initialized
        foreach ($request->getLockedPackages() as $lockedPackage) {
            if (!($lockedPackage instanceof AliasPackage) && $lockedPackage->getName() === $name) {
                if (false !== $index = array_search($lockedPackage, $this->packages, true)) {
                    $request->unlockPackage($lockedPackage);
                    $this->removeLoadedPackage($request, $repositories, $lockedPackage, $index);

                    // make sure that any requirements for this package by other locked or fixed packages are now
                    // also loaded, as they were previously ignored because the locked (now unlocked) package already
                    // satisfied their requirements
                    // and if this package is replacing another that is required by a locked or fixed package, ensure
                    // that we load that replaced package in case an update to this package removes the replacement
                    foreach ($request->getFixedOrLockedPackages() as $fixedOrLockedPackage) {
                        if ($fixedOrLockedPackage === $lockedPackage) {
                            continue;
                        }

                        if (isset($this->skippedLoad[$fixedOrLockedPackage->getName()])) {
                            $requires = $fixedOrLockedPackage->getRequires();
                            if (isset($requires[$lockedPackage->getName()])) {
                                $this->markPackageNameForLoading($request, $lockedPackage->getName(), $requires[$lockedPackage->getName()]->getConstraint());
                            }

                            foreach ($lockedPackage->getReplaces() as $replace) {
                                if (isset($requires[$replace->getTarget()], $this->skippedLoad[$replace->getTarget()])) {
                                    $this->unlockPackage($request, $repositories, $replace->getTarget());
                                    // this package is in $requires so no need to call markPackageNameForLoadingIfRequired
                                    $this->markPackageNameForLoading($request, $replace->getTarget(), $replace->getConstraint());
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    private function markPackageNameForLoadingIfRequired(Request $request, string $name): void
    {
        if ($this->isRootRequire($request, $name)) {
            $this->markPackageNameForLoading($request, $name, $request->getRequires()[$name]);
        }

        foreach ($this->packages as $package) {
            foreach ($package->getRequires() as $link) {
                if ($name === $link->getTarget()) {
                    $this->markPackageNameForLoading($request, $link->getTarget(), $link->getConstraint());
                }
            }
        }
    }

    /**
     * @param RepositoryInterface[] $repositories
     */
    private function removeLoadedPackage(Request $request, array $repositories, BasePackage $package, int $index): void
    {
        $repoIndex = array_search($package->getRepository(), $repositories, true);

        unset($this->loadedPerRepo[$repoIndex][$package->getName()][$package->getVersion()]);
        unset($this->packages[$index]);
        if (isset($this->aliasMap[spl_object_hash($package)])) {
            foreach ($this->aliasMap[spl_object_hash($package)] as $aliasIndex => $aliasPackage) {
                unset($this->loadedPerRepo[$repoIndex][$aliasPackage->getName()][$aliasPackage->getVersion()]);
                unset($this->packages[$aliasIndex]);
            }
            unset($this->aliasMap[spl_object_hash($package)]);
        }
    }

    private function runOptimizer(Request $request, Pool $pool): Pool
    {
        if (null === $this->poolOptimizer) {
            return $pool;
        }

        $this->io->debug('Running pool optimizer.');

        $before = microtime(true);
        $total = \count($pool->getPackages());

        $pool = $this->poolOptimizer->optimize($request, $pool);

        $filtered = $total - \count($pool->getPackages());

        if (0 === $filtered) {
            return $pool;
        }

        $this->io->write(sprintf('Pool optimizer completed in %.3f seconds', microtime(true) - $before), true, IOInterface::VERY_VERBOSE);
        $this->io->write(sprintf(
            '<info>Found %s package versions referenced in your dependency graph. %s (%d%%) were optimized away.</info>',
            number_format($total),
            number_format($filtered),
            round(100 / $total * $filtered)
        ), true, IOInterface::VERY_VERBOSE);

        return $pool;
    }

    /**
     * @param RepositoryInterface[] $repositories
     */
    private function runSecurityAdvisoryFilter(Pool $pool, array $repositories, Request $request): Pool
    {
        if (null === $this->securityAdvisoryPoolFilter) {
            return $pool;
        }

        $this->io->debug('Running security advisory pool filter.');

        $before = microtime(true);
        $total = \count($pool->getPackages());

        $pool = $this->securityAdvisoryPoolFilter->filter($pool, $repositories, $request);

        $filtered = $total - \count($pool->getPackages());

        if (0 === $filtered) {
            return $pool;
        }

        $this->io->write(sprintf('Security advisory pool filter completed in %.3f seconds', microtime(true) - $before), true, IOInterface::VERY_VERBOSE);
        $this->io->write(sprintf(
            '<info>Found %s package versions referenced in your dependency graph. %s (%d%%) were filtered away.</info>',
            number_format($total),
            number_format($filtered),
            round(100 / $total * $filtered)
        ), true, IOInterface::VERY_VERBOSE);

        return $pool;
    }

    /**
     * @param RepositoryInterface[] $repositories
     */
    private function runReleaseAgeFilter(Pool $pool, array $repositories, Request $request): Pool
    {
        if (null === $this->releaseAgePoolFilter) {
            return $pool;
        }

        $this->io->debug('Running release age pool filter.');

        // Pass security advisories to enable security fix detection
        // Security fixes bypass the release age requirement to ensure fast propagation
        if (null !== $this->securityAdvisoryPoolFilter) {
            $this->releaseAgePoolFilter->setSecurityAdvisories($this->securityAdvisoryPoolFilter->getAdvisoryMap());
        }

        $before = microtime(true);
        $total = \count($pool->getPackages());

        $pool = $this->releaseAgePoolFilter->filter($pool, $repositories, $request);

        $filtered = $total - \count($pool->getPackages());

        if (0 === $filtered) {
            return $pool;
        }

        $this->io->write(sprintf('Release age pool filter completed in %.3f seconds', microtime(true) - $before), true, IOInterface::VERY_VERBOSE);
        $this->io->write(sprintf(
            '<info>%d package version(s) filtered due to minimum-release-age policy.</info>',
            $filtered
        ), true, IOInterface::VERY_VERBOSE);

        return $pool;
    }
}
