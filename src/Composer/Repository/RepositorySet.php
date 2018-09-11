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
use Composer\Package\BasePackage;
use Composer\Package\Version\VersionParser;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Semver\Constraint\ConstraintInterface;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class RepositorySet
{
    /** @var array */
    private $rootAliases;

    /** @var RepositoryInterface[] */
    private $repositories;

    /** @var ComposerRepository[] */
    private $providerRepos;

    private $acceptableStabilities;
    private $stabilityFlags;
    protected $filterRequires;

    /** @var Pool */
    private $pool; // TODO remove this

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
        if ($repo instanceof CompositeRepository) {
            $repos = $repo->getRepositories();
        } else {
            $repos = array($repo);
        }

        foreach ($repos as $repo) {
            $this->repositories[] = $repo;
            if ($repo instanceof ComposerRepository && $repo->hasProviders()) {
                $this->providerRepos[] = $repo;
            }
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

    /**
     * Create a pool for dependency resolution from the packages in this repository set.
     *
     * @return Pool
     */
    public function createPool()
    {
        if ($this->pool) {
            return $this->pool;
        }

        $this->pool = new Pool($this->acceptableStabilities, $this->stabilityFlags, $this->filterRequires);

        foreach ($this->repositories as $repository) {
            $this->pool->addRepository($repository, $this->rootAliases);
        }

        return $this->pool;
    }

    // TODO get rid of this function
    public function getPoolTemp()
    {
        if (!$this->pool) {
            return $this->createPool();
        } else {
            return $this->pool;
        }
    }
}
