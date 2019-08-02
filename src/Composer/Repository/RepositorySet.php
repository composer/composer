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
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Test\DependencyResolver\PoolTest;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class RepositorySet
{
    /** @var array */
    private $rootAliases;

    /** @var RepositoryInterface[] */
    private $repositories = array();

    private $acceptableStabilities;
    private $stabilityFlags;
    protected $filterRequires;

    /** @var Pool */
    private $pool;

    public function __construct(array $rootAliases = array(), $minimumStability = 'stable', array $stabilityFlags = array(), array $filterRequires = array())
    {
        $this->rootAliases = $rootAliases;

        $this->acceptableStabilities = array();
        foreach (BasePackage::$stabilities as $stability => $value) {
            if ($value <= BasePackage::$stabilities[$minimumStability]) {
                $this->acceptableStabilities[$stability] = $value;
            }
        }
        $this->stabilityFlags = $stabilityFlags;
        $this->filterRequires = $filterRequires;
        foreach ($filterRequires as $name => $constraint) {
            if (preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $name)) {
                unset($this->filterRequires[$name]);
            }
        }
    }

    /**
     * Adds a repository to this repository set
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

    public function isPackageAcceptable($name, $stability)
    {
        foreach ((array) $name as $n) {
            // allow if package matches the global stability requirement and has no exception
            if (!isset($this->stabilityFlags[$n]) && isset($this->acceptableStabilities[$stability])) {
                return true;
            }

            // allow if package matches the package-specific stability flag
            if (isset($this->stabilityFlags[$n]) && BasePackage::$stabilities[$stability] <= $this->stabilityFlags[$n]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find packages providing or matching a name and optionally meeting a constraint in all repositories
     *
     * @param string $name
     * @param ConstraintInterface|null $constraint
     * @param bool $exactMatch
     * @return array
     */
    public function findPackages($name, ConstraintInterface $constraint = null, $exactMatch = true)
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

            if ($this->isPackageAcceptable($candidate->getNames(), $candidate->getStability())) {
                $result[] = $candidate;
            }
        }

        return $candidates;
    }

    public function getPriority(RepositoryInterface $repo)
    {
        $priority = array_search($repo, $this->repositories, true);

        if (false === $priority) {
            throw new \RuntimeException("Could not determine repository priority. The repository was not registered in the pool.");
        }

        return -$priority;
    }

    /**
     * Create a pool for dependency resolution from the packages in this repository set.
     *
     * @return Pool
     */
    public function createPool(Request $request)
    {
        $poolBuilder = new PoolBuilder(array($this, 'isPackageAcceptable'), $this->filterRequires);

        return $this->pool = $poolBuilder->buildPool($this->repositories, $this->rootAliases, $request);
    }

    // TODO unify this with above in some simpler version without "request"?
    public function createPoolForPackage($packageName)
    {
        return $this->createPoolForPackages(array($packageName));
    }

    public function createPoolForPackages($packageNames)
    {
        $request = new Request();
        foreach ($packageNames as $packageName) {
            $request->install($packageName);
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
