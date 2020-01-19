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

namespace Composer\Repository;

use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\PoolBuilder;
use Composer\DependencyResolver\Request;
use Composer\Package\BasePackage;
use Composer\Package\Version\VersionParser;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\LockArrayRepository;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Package\Version\StabilityFilter;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class RepositorySet
{
    /** @var array */
    private $rootAliases;
    /** @var array */
    private $rootReferences;

    /** @var RepositoryInterface[] */
    private $repositories = array();

    private $acceptableStabilities;
    private $stabilityFlags;
    protected $rootRequires;

    /** @var Pool */
    private $pool;

    public function __construct(array $rootAliases = array(), array $rootReferences = array(), $minimumStability = 'stable', array $stabilityFlags = array(), array $rootRequires = array())
    {
        $this->rootAliases = $rootAliases;
        $this->rootReferences = $rootReferences;

        $this->acceptableStabilities = array();
        foreach (BasePackage::$stabilities as $stability => $value) {
            if ($value <= BasePackage::$stabilities[$minimumStability]) {
                $this->acceptableStabilities[$stability] = $value;
            }
        }
        $this->stabilityFlags = $stabilityFlags;
        $this->rootRequires = $rootRequires;
        foreach ($rootRequires as $name => $constraint) {
            if (preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $name)) {
                unset($this->rootRequires[$name]);
            }
        }
    }

    /**
     * Adds a repository to this repository set
     *
     * The first repos added have a higher priority. As soon as a package is found in any
     * repository the search for that package ends, and following repos will not be consulted.
     *
     * @param RepositoryInterface $repo        A package repository
     */
    public function addRepository(RepositoryInterface $repo)
    {
        if ($this->pool) {
            throw new \RuntimeException("Pool has already been created from this repository set, it cannot be modified anymore.");
        }

        if ($repo instanceof CompositeRepository) {
            $repos = $repo->getRepositories();
        } else {
            $repos = array($repo);
        }

        foreach ($repos as $repo) {
            $this->repositories[] = $repo;
        }
    }

    /**
     * Find packages providing or matching a name and optionally meeting a constraint in all repositories
     *
     * Returned in the order of repositories, matching priority
     *
     * @param string $name
     * @param ConstraintInterface|null $constraint
     * @param bool $exactMatch if set to false, packages which replace/provide the given name might be returned as well even if they do not match the name exactly
     * @param bool $ignoreStability if set to true, packages are returned even though their stability does not match the required stability
     * @return array
     */
    public function findPackages($name, ConstraintInterface $constraint = null, $exactMatch = true, $ignoreStability = false)
    {
        $packages = array();
        foreach ($this->repositories as $repository) {
            $packages[] = $repository->findPackages($name, $constraint) ?: array();
        }

        $candidates = $packages ? call_user_func_array('array_merge', $packages) : array();

        $result = array();
        foreach ($candidates as $candidate) {
            if ($exactMatch && $candidate->getName() !== $name) {
                continue;
            }

            if (!$ignoreStability && $this->isPackageAcceptable($candidate->getNames(), $candidate->getStability())) {
                $result[] = $candidate;
            }
        }

        return $candidates;
    }

    public function isPackageAcceptable($names, $stability)
    {
        return StabilityFilter::isPackageAcceptable($this->acceptableStabilities, $this->stabilityFlags, $names, $stability);
    }

    /**
     * Create a pool for dependency resolution from the packages in this repository set.
     *
     * @return Pool
     */
    public function createPool(Request $request)
    {
        $poolBuilder = new PoolBuilder($this->acceptableStabilities, $this->stabilityFlags, $this->rootAliases, $this->rootReferences, $this->rootRequires);

        foreach ($this->repositories as $repo) {
            if ($repo instanceof InstalledRepositoryInterface) {
                throw new \LogicException('The pool can not accept packages from an installed repository');
            }
        }

        return $this->pool = $poolBuilder->buildPool($this->repositories, $request);
    }

    // TODO unify this with above in some simpler version without "request"?
    public function createPoolForPackage($packageName, LockArrayRepository $lockedRepo = null)
    {
        return $this->createPoolForPackages(array($packageName), $lockedRepo);
    }

    public function createPoolForPackages($packageNames, LockArrayRepository $lockedRepo = null)
    {
        $request = new Request($lockedRepo);

        foreach ($packageNames as $packageName) {
            $request->require($packageName);
        }

        return $this->createPool($request);
    }

    /**
     * Access the pool object after it has been created, relevant for plugins which need to read info from the pool
     * @return Pool
     */
    public function getPool()
    {
        return $this->pool;
    }
}
