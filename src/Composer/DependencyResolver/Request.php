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
    public const UPDATE_ONLY_LISTED = 0;

    /**
     * Identifies a partial update for listed packages and recursively all their dependencies, however dependencies
     * also directly required by the root composer.json and their dependencies will remain at the locked version.
     */
    public const UPDATE_LISTED_WITH_TRANSITIVE_DEPS_NO_ROOT_REQUIRE = 1;

    /**
     * Identifies a partial update for listed packages and recursively all their dependencies, even dependencies
     * also directly required by the root composer.json will be updated.
     */
    public const UPDATE_LISTED_WITH_TRANSITIVE_DEPS = 2;

    /** @var ?LockArrayRepository */
    protected $lockedRepository;
    /** @var array<string, ConstraintInterface> */
    protected $requires = [];
    /** @var array<string, BasePackage> */
    protected $fixedPackages = [];
    /** @var array<string, BasePackage> */
    protected $lockedPackages = [];
    /** @var array<string, BasePackage> */
    protected $fixedLockedPackages = [];
    /** @var array<string> */
    protected $updateAllowList = [];
    /** @var false|self::UPDATE_* */
    protected $updateAllowTransitiveDependencies = false;
    /** @var non-empty-list<string>|null */
    private $restrictedPackages = null;

    public function __construct(?LockArrayRepository $lockedRepository = null)
    {
        $this->lockedRepository = $lockedRepository;
    }

    public function requireName(string $packageName, ?ConstraintInterface $constraint = null): void
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
    public function fixPackage(BasePackage $package): void
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
    public function lockPackage(BasePackage $package): void
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
    public function fixLockedPackage(BasePackage $package): void
    {
        $this->fixedPackages[spl_object_hash($package)] = $package;
        $this->fixedLockedPackages[spl_object_hash($package)] = $package;
    }

    public function unlockPackage(BasePackage $package): void
    {
        unset($this->lockedPackages[spl_object_hash($package)]);
    }

    /**
     * @param array<string> $updateAllowList
     * @param false|self::UPDATE_* $updateAllowTransitiveDependencies
     */
    public function setUpdateAllowList(array $updateAllowList, $updateAllowTransitiveDependencies): void
    {
        $this->updateAllowList = $updateAllowList;
        $this->updateAllowTransitiveDependencies = $updateAllowTransitiveDependencies;
    }

    /**
     * @return array<string>
     */
    public function getUpdateAllowList(): array
    {
        return $this->updateAllowList;
    }

    public function getUpdateAllowTransitiveDependencies(): bool
    {
        return $this->updateAllowTransitiveDependencies !== self::UPDATE_ONLY_LISTED;
    }

    public function getUpdateAllowTransitiveRootDependencies(): bool
    {
        return $this->updateAllowTransitiveDependencies === self::UPDATE_LISTED_WITH_TRANSITIVE_DEPS;
    }

    /**
     * @return array<string, ConstraintInterface>
     */
    public function getRequires(): array
    {
        return $this->requires;
    }

    /**
     * @return array<string, BasePackage>
     */
    public function getFixedPackages(): array
    {
        return $this->fixedPackages;
    }

    public function isFixedPackage(BasePackage $package): bool
    {
        return isset($this->fixedPackages[spl_object_hash($package)]);
    }

    /**
     * @return array<string, BasePackage>
     */
    public function getLockedPackages(): array
    {
        return $this->lockedPackages;
    }

    public function isLockedPackage(PackageInterface $package): bool
    {
        return isset($this->lockedPackages[spl_object_hash($package)]) || isset($this->fixedLockedPackages[spl_object_hash($package)]);
    }

    /**
     * @return array<string, BasePackage>
     */
    public function getFixedOrLockedPackages(): array
    {
        return array_merge($this->fixedPackages, $this->lockedPackages);
    }

    /**
     * @return ($packageIds is true ? array<int, BasePackage> : array<string, BasePackage>)
     *
     * @TODO look into removing the packageIds option, the only place true is used
     *       is for the installed map in the solver problems.
     *       Some locked packages may not be in the pool,
     *       so they have a package->id of -1
     */
    public function getPresentMap(bool $packageIds = false): array
    {
        $presentMap = [];

        if ($this->lockedRepository !== null) {
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
     * @return array<int, BasePackage>
     */
    public function getFixedPackagesMap(): array
    {
        $fixedPackagesMap = [];

        foreach ($this->fixedPackages as $package) {
            $fixedPackagesMap[$package->getId()] = $package;
        }

        return $fixedPackagesMap;
    }

    /**
     * @return ?LockArrayRepository
     */
    public function getLockedRepository(): ?LockArrayRepository
    {
        return $this->lockedRepository;
    }

    /**
     * Restricts the pool builder from loading other packages than those listed here
     *
     * @param non-empty-list<string> $names
     */
    public function restrictPackages(array $names): void
    {
        $this->restrictedPackages = $names;
    }

    /**
     * @return list<string>
     */
    public function getRestrictedPackages(): ?array
    {
        return $this->restrictedPackages;
    }
}
