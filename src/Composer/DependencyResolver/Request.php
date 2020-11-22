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

use Composer\Package\Package;
use Composer\Package\PackageInterface;
use Composer\Repository\LockArrayRepository;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\MatchAllConstraint;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class Request
{
    /**
     * Identifies a partial update for listed packages only, all dependencies will remain at locked versions
     */
    const UPDATE_ONLY_LISTED = 0;

    /**
     * Identifies a partial update for listed packages and recursively all their dependencies, however dependencies
     * also directly required by the root composer.json and their dependencies will remain at the locked version.
     */
    const UPDATE_LISTED_WITH_TRANSITIVE_DEPS_NO_ROOT_REQUIRE = 1;

    /**
     * Identifies a partial update for listed packages and recursively all their dependencies, even dependencies
     * also directly required by the root composer.json will be updated.
     */
    const UPDATE_LISTED_WITH_TRANSITIVE_DEPS = 2;

    protected $lockedRepository;
    protected $requires = array();
    protected $fixedPackages = array();
    protected $lockedPackages = array();
    protected $fixedLockedPackages = array();
    protected $updateAllowList = array();
    protected $updateAllowTransitiveDependencies = false;

    public function __construct(LockArrayRepository $lockedRepository = null)
    {
        $this->lockedRepository = $lockedRepository;
    }

    public function requireName($packageName, ConstraintInterface $constraint = null)
    {
        $packageName = strtolower($packageName);

        if ($constraint === null) {
            $constraint = new MatchAllConstraint();
        }
        if (isset($this->requires[$packageName])) {
            throw new \LogicException('Overwriting requires seems like a bug ('.$packageName.' '.$this->requires[$packageName]->getPrettyString().' => '.$constraint->getPrettyString().', check why it is happening, might be a root alias');
        }
        $this->requires[$packageName] = $constraint;
    }

    /**
     * Mark a package as currently present and having to remain installed
     *
     * This is used for platform packages which cannot be modified by Composer. A rule enforcing their installation is
     * generated for dependency resolution. Partial updates with dependencies cannot in any way modify these packages.
     */
    public function fixPackage(PackageInterface $package)
    {
        $this->fixedPackages[spl_object_hash($package)] = $package;
    }

    /**
     * Mark a package as locked to a specific version but removable
     *
     * This is used for lock file packages which need to be treated similar to fixed packages by the pool builder in
     * that by default they should really only have the currently present version loaded and no remote alternatives.
     *
     * However unlike fixed packages there will not be a special rule enforcing their installation for the solver, so
     * if nothing requires these packages they will be removed. Additionally in a partial update these packages can be
     * unlocked, meaning other versions can be installed if explicitly requested as part of the update.
     */
    public function lockPackage(PackageInterface $package)
    {
        $this->lockedPackages[spl_object_hash($package)] = $package;
    }

    /**
     * Marks a locked package fixed. So it's treated irremovable like a platform package.
     *
     * This is necessary for the composer install step which verifies the lock file integrity and should not allow
     * removal of any packages. At the same time lock packages there cannot simply be marked fixed, as error reporting
     * would then report them as platform packages, so this still marks them as locked packages at the same time.
     */
    public function fixLockedPackage(PackageInterface $package)
    {
        $this->fixedPackages[spl_object_hash($package)] = $package;
        $this->fixedLockedPackages[spl_object_hash($package)] = $package;
    }

    public function unlockPackage(PackageInterface $package)
    {
        unset($this->lockedPackages[spl_object_hash($package)]);
    }

    public function setUpdateAllowList($updateAllowList, $updateAllowTransitiveDependencies)
    {
        $this->updateAllowList = $updateAllowList;
        $this->updateAllowTransitiveDependencies = $updateAllowTransitiveDependencies;
    }

    public function getUpdateAllowList()
    {
        return $this->updateAllowList;
    }

    public function getUpdateAllowTransitiveDependencies()
    {
        return $this->updateAllowTransitiveDependencies !== self::UPDATE_ONLY_LISTED;
    }

    public function getUpdateAllowTransitiveRootDependencies()
    {
        return $this->updateAllowTransitiveDependencies === self::UPDATE_LISTED_WITH_TRANSITIVE_DEPS;
    }

    public function getRequires()
    {
        return $this->requires;
    }

    public function getFixedPackages()
    {
        return $this->fixedPackages;
    }

    public function isFixedPackage(PackageInterface $package)
    {
        return isset($this->fixedPackages[spl_object_hash($package)]);
    }

    public function getLockedPackages()
    {
        return $this->lockedPackages;
    }

    public function isLockedPackage(PackageInterface $package)
    {
        return isset($this->lockedPackages[spl_object_hash($package)]) || isset($this->fixedLockedPackages[spl_object_hash($package)]);
    }

    public function getFixedOrLockedPackages()
    {
        return array_merge($this->fixedPackages, $this->lockedPackages);
    }

    // TODO look into removing the packageIds option, the only place true is used is for the installed map in the solver problems
    // some locked packages may not be in the pool so they have a package->id of -1
    public function getPresentMap($packageIds = false)
    {
        $presentMap = array();

        if ($this->lockedRepository) {
            foreach ($this->lockedRepository->getPackages() as $package) {
                $presentMap[$packageIds ? $package->id : spl_object_hash($package)] = $package;
            }
        }

        foreach ($this->fixedPackages as $package) {
            $presentMap[$packageIds ? $package->id : spl_object_hash($package)] = $package;
        }

        return $presentMap;
    }

    public function getFixedPackagesMap()
    {
        $fixedPackagesMap = array();

        foreach ($this->fixedPackages as $package) {
            $fixedPackagesMap[$package->id] = $package;
        }

        return $fixedPackagesMap;
    }

    public function getLockedRepository()
    {
        return $this->lockedRepository;
    }
}
