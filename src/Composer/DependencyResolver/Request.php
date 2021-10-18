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

use Composer\Package\BasePackage;
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

    /** @var ?LockArrayRepository */
    protected $lockedRepository;
    /** @var array<string, ConstraintInterface> */
    protected $requires = array();
    /** @var array<string, BasePackage> */
    protected $fixedPackages = array();
    /** @var array<string, BasePackage> */
    protected $lockedPackages = array();
    /** @var array<string, BasePackage> */
    protected $fixedLockedPackages = array();
    /** @var string[] */
    protected $updateAllowList = array();
    /** @var false|self::UPDATE_* */
    protected $updateAllowTransitiveDependencies = false;

    public function __construct(LockArrayRepository $lockedRepository = null)
    {
        $this->lockedRepository = $lockedRepository;
    }

    /**
     * @param string $packageName
     * @return void
     */
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
     *
     * @return void
     */
    public function fixPackage(BasePackage $package)
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
     *
     * @return void
     */
    public function lockPackage(BasePackage $package)
    {
        $this->lockedPackages[spl_object_hash($package)] = $package;
    }

    /**
     * Marks a locked package fixed. So it's treated irremovable like a platform package.
     *
     * This is necessary for the composer install step which verifies the lock file integrity and should not allow
     * removal of any packages. At the same time lock packages there cannot simply be marked fixed, as error reporting
     * would then report them as platform packages, so this still marks them as locked packages at the same time.
     *
     * @return void
     */
    public function fixLockedPackage(BasePackage $package)
    {
        $this->fixedPackages[spl_object_hash($package)] = $package;
        $this->fixedLockedPackages[spl_object_hash($package)] = $package;
    }

    /**
     * @return void
     */
    public function unlockPackage(BasePackage $package)
    {
        unset($this->lockedPackages[spl_object_hash($package)]);
    }

    /**
     * @param string[] $updateAllowList
     * @param false|self::UPDATE_* $updateAllowTransitiveDependencies
     * @return void
     */
    public function setUpdateAllowList($updateAllowList, $updateAllowTransitiveDependencies)
    {
        $this->updateAllowList = $updateAllowList;
        $this->updateAllowTransitiveDependencies = $updateAllowTransitiveDependencies;
    }

    /**
     * @return string[]
     */
    public function getUpdateAllowList()
    {
        return $this->updateAllowList;
    }

    /**
     * @return bool
     */
    public function getUpdateAllowTransitiveDependencies()
    {
        return $this->updateAllowTransitiveDependencies !== self::UPDATE_ONLY_LISTED;
    }

    /**
     * @return bool
     */
    public function getUpdateAllowTransitiveRootDependencies()
    {
        return $this->updateAllowTransitiveDependencies === self::UPDATE_LISTED_WITH_TRANSITIVE_DEPS;
    }

    /**
     * @return array<string, ConstraintInterface>
     */
    public function getRequires()
    {
        return $this->requires;
    }

    /**
     * @return array<string, BasePackage>
     */
    public function getFixedPackages()
    {
        return $this->fixedPackages;
    }

    /**
     * @return bool
     */
    public function isFixedPackage(BasePackage $package)
    {
        return isset($this->fixedPackages[spl_object_hash($package)]);
    }

    /**
     * @return array<string, BasePackage>
     */
    public function getLockedPackages()
    {
        return $this->lockedPackages;
    }

    /**
     * @return bool
     */
    public function isLockedPackage(PackageInterface $package)
    {
        return isset($this->lockedPackages[spl_object_hash($package)]) || isset($this->fixedLockedPackages[spl_object_hash($package)]);
    }

    /**
     * @return array<string, BasePackage>
     */
    public function getFixedOrLockedPackages()
    {
        return array_merge($this->fixedPackages, $this->lockedPackages);
    }

    /**
     * @param bool $packageIds
     * @return array<int|string, BasePackage>
     *
     * @TODO look into removing the packageIds option, the only place true is used
     *       is for the installed map in the solver problems.
     *       Some locked packages may not be in the pool,
     *       so they have a package->id of -1
     */
    public function getPresentMap($packageIds = false)
    {
        $presentMap = array();

        if ($this->lockedRepository) {
            foreach ($this->lockedRepository->getPackages() as $package) {
                $presentMap[$packageIds ? $package->getId() : spl_object_hash($package)] = $package;
            }
        }

        foreach ($this->fixedPackages as $package) {
            $presentMap[$packageIds ? $package->getId() : spl_object_hash($package)] = $package;
        }

        return $presentMap;
    }

    /**
     * @return BasePackage[]
     */
    public function getFixedPackagesMap()
    {
        $fixedPackagesMap = array();

        foreach ($this->fixedPackages as $package) {
            $fixedPackagesMap[$package->getId()] = $package;
        }

        return $fixedPackagesMap;
    }

    /**
     * @return ?LockArrayRepository
     */
    public function getLockedRepository()
    {
        return $this->lockedRepository;
    }
}
