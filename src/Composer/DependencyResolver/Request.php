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
use Composer\Package\RootAliasPackage;
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
     * Mark an existing package as being installed and having to remain installed
     *
     * @param bool $lockable if set to false, the package will not be written to the lock file
     */
    public function fixPackage(PackageInterface $package)
    {
        $this->fixedPackages[spl_object_hash($package)] = $package;
    }

    /**
     * Mark an existing package as installed but removable
     */
    public function lockPackage(PackageInterface $package)
    {
        $this->lockedPackages[spl_object_hash($package)] = $package;
    }

    /**
     * Mark a package fixed, but also keep track it is from the lock file (needed for composer install error reporting)
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
