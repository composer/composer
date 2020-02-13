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
use Composer\EventDispatcher\EventDispatcher;
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
    /**
     * Packages are returned even though their stability does not match the required stability
     */
    const ALLOW_UNACCEPTABLE_STABILITIES = 1;
    /**
     * Packages will be looked up in all repositories, even after they have been found in a higher prio one
     */
    const ALLOW_SHADOWED_REPOSITORIES = 2;

    /** @var array */
    private $rootAliases;
    /** @var array */
    private $rootReferences;

    /** @var RepositoryInterface[] */
    private $repositories = array();

    private $acceptableStabilities;
    private $stabilityFlags;
    private $rootRequires;

    /** @var bool */
    private $locked = false;
    /** @var bool */
    private $allowInstalledRepositories = false;

    public function __construct($minimumStability = 'stable', array $stabilityFlags = array(), array $rootAliases = array(), array $rootReferences = array(), array $rootRequires = array())
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

    public function allowInstalledRepositories($allow = true)
    {
        $this->allowInstalledRepositories = $allow;
    }

    public function getRootRequires()
    {
        return $this->rootRequires;
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
        if ($this->locked) {
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
     * @param int $flags any of the ALLOW_* constants from this class to tweak what is returned
     * @return array
     */
    public function findPackages($name, ConstraintInterface $constraint = null, $flags = 0)
    {
        $ignoreStability = ($flags & self::ALLOW_UNACCEPTABLE_STABILITIES) !== 0;
        $loadFromAllRepos = ($flags & self::ALLOW_SHADOWED_REPOSITORIES) !== 0;

        $packages = array();
        if ($loadFromAllRepos) {
            foreach ($this->repositories as $repository) {
                $packages[] = $repository->findPackages($name, $constraint) ?: array();
            }
        } else {
            foreach ($this->repositories as $repository) {
                $result = $repository->loadPackages(array($name => $constraint), $ignoreStability ? BasePackage::$stabilities : $this->acceptableStabilities, $ignoreStability ? array() : $this->stabilityFlags);

                $packages[] = $result['packages'];
                foreach ($result['namesFound'] as $nameFound) {
                    // avoid loading the same package again from other repositories once it has been found
                    if ($name === $nameFound) {
                        break 2;
                    }
                }
            }
        }

        $candidates = $packages ? call_user_func_array('array_merge', $packages) : array();

        // when using loadPackages above (!$loadFromAllRepos) the repos already filter for stability so no need to do it again
        if ($ignoreStability || !$loadFromAllRepos) {
            return $candidates;
        }

        $result = array();
        foreach ($candidates as $candidate) {
            if ($this->isPackageAcceptable($candidate->getNames(), $candidate->getStability())) {
                $result[] = $candidate;
            }
        }

        return $candidates;
    }

    public function getProviders($packageName)
    {
        foreach ($this->repositories as $repository) {
            if ($repository instanceof ComposerRepository) {
                if ($providers = $repository->getProviders($packageName)) {
                    return $providers;
                }
            }
        }

        return array();
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
    public function createPool(Request $request, EventDispatcher $eventDispatcher = null)
    {
        $poolBuilder = new PoolBuilder($this->acceptableStabilities, $this->stabilityFlags, $this->rootAliases, $this->rootReferences, $eventDispatcher);

        foreach ($this->repositories as $repo) {
            if ($repo instanceof InstalledRepositoryInterface && !$this->allowInstalledRepositories) {
                throw new \LogicException('The pool can not accept packages from an installed repository');
            }
        }

        $this->locked = true;

        return $poolBuilder->buildPool($this->repositories, $request);
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
            $request->requireName($packageName);
        }

        return $this->createPool($request);
    }
}
