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

use Composer\DependencyResolver\PoolOptimizer;
use Composer\DependencyResolver\PolicyInterface;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\PoolBuilder;
use Composer\DependencyResolver\Request;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Package\BasePackage;
use Composer\Package\AliasPackage;
use Composer\Package\CompleteAliasPackage;
use Composer\Package\CompletePackage;
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

    /** @var RepositoryInterface[] */
    private $repositories = array();

    /**
     * @var int[] array of stability => BasePackage::STABILITY_* value
     * @phpstan-var array<string, BasePackage::STABILITY_*>
     */
    private $acceptableStabilities;

    /**
     * @var int[] array of package name => BasePackage::STABILITY_* value
     * @phpstan-var array<string, BasePackage::STABILITY_*>
     */
    private $stabilityFlags;

    /**
     * @var ConstraintInterface[]
     * @phpstan-var array<string, ConstraintInterface>
     */
    private $rootRequires;

    /** @var bool */
    private $locked = false;
    /** @var bool */
    private $allowInstalledRepositories = false;

    /**
     * In most cases if you are looking to use this class as a way to find packages from repositories
     * passing minimumStability is all you need to worry about. The rest is for advanced pool creation including
     * aliases, pinned references and other special cases.
     *
     * @param string $minimumStability
     * @param int[]  $stabilityFlags   an array of package name => BasePackage::STABILITY_* value
     * @phpstan-param array<string, BasePackage::STABILITY_*> $stabilityFlags
     * @param array[] $rootAliases
     * @phpstan-param list<array{package: string, version: string, alias: string, alias_normalized: string}> $rootAliases
     * @param string[] $rootReferences an array of package name => source reference
     * @phpstan-param array<string, string> $rootReferences
     * @param ConstraintInterface[] $rootRequires an array of package name => constraint from the root package
     * @phpstan-param array<string, ConstraintInterface> $rootRequires
     */
    public function __construct($minimumStability = 'stable', array $stabilityFlags = array(), array $rootAliases = array(), array $rootReferences = array(), array $rootRequires = array())
    {
        $this->rootAliases = self::getRootAliasesPerPackage($rootAliases);
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
            if (PlatformRepository::isPlatformPackage($name)) {
                unset($this->rootRequires[$name]);
            }
        }
    }

    /**
     * @param bool $allow
     *
     * @return void
     */
    public function allowInstalledRepositories($allow = true)
    {
        $this->allowInstalledRepositories = $allow;
    }

    /**
     * @return ConstraintInterface[] an array of package name => constraint from the root package, platform requirements excluded
     * @phpstan-return array<string, ConstraintInterface>
     */
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
     * @param RepositoryInterface $repo A package repository
     *
     * @return void
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
     * @param  string                   $name
     * @param  ConstraintInterface|null $constraint
     * @param  int                      $flags      any of the ALLOW_* constants from this class to tweak what is returned
     * @return BasePackage[]
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

        return $result;
    }

    /**
     * @param  string   $packageName
     *
     * @return array[] an array with the provider name as key and value of array('name' => '...', 'description' => '...', 'type' => '...')
     * @phpstan-return array<string, array{name: string, description: string, type: string}>
     */
    public function getProviders($packageName)
    {
        $providers = array();
        foreach ($this->repositories as $repository) {
            if ($repoProviders = $repository->getProviders($packageName)) {
                $providers = array_merge($providers, $repoProviders);
            }
        }

        return $providers;
    }

    /**
     * Check for each given package name whether it would be accepted by this RepositorySet in the given $stability
     *
     * @param  string[] $names
     * @param  string   $stability one of 'stable', 'RC', 'beta', 'alpha' or 'dev'
     * @return bool
     */
    public function isPackageAcceptable($names, $stability)
    {
        return StabilityFilter::isPackageAcceptable($this->acceptableStabilities, $this->stabilityFlags, $names, $stability);
    }

    /**
     * Create a pool for dependency resolution from the packages in this repository set.
     *
     * @return Pool
     */
    public function createPool(Request $request, IOInterface $io, EventDispatcher $eventDispatcher = null, PoolOptimizer $poolOptimizer = null)
    {
        $poolBuilder = new PoolBuilder($this->acceptableStabilities, $this->stabilityFlags, $this->rootAliases, $this->rootReferences, $io, $eventDispatcher, $poolOptimizer);

        foreach ($this->repositories as $repo) {
            if (($repo instanceof InstalledRepositoryInterface || $repo instanceof InstalledRepository) && !$this->allowInstalledRepositories) {
                throw new \LogicException('The pool can not accept packages from an installed repository');
            }
        }

        $this->locked = true;

        return $poolBuilder->buildPool($this->repositories, $request);
    }

    /**
     * Create a pool for dependency resolution from the packages in this repository set.
     *
     * @return Pool
     */
    public function createPoolWithAllPackages()
    {
        foreach ($this->repositories as $repo) {
            if (($repo instanceof InstalledRepositoryInterface || $repo instanceof InstalledRepository) && !$this->allowInstalledRepositories) {
                throw new \LogicException('The pool can not accept packages from an installed repository');
            }
        }

        $this->locked = true;

        $packages = array();
        foreach ($this->repositories as $repository) {
            foreach ($repository->getPackages() as $package) {
                $packages[] = $package;

                if (isset($this->rootAliases[$package->getName()][$package->getVersion()])) {
                    $alias = $this->rootAliases[$package->getName()][$package->getVersion()];
                    while ($package instanceof AliasPackage) {
                        $package = $package->getAliasOf();
                    }
                    if ($package instanceof CompletePackage) {
                        $aliasPackage = new CompleteAliasPackage($package, $alias['alias_normalized'], $alias['alias']);
                    } else {
                        $aliasPackage = new AliasPackage($package, $alias['alias_normalized'], $alias['alias']);
                    }
                    $aliasPackage->setRootPackageAlias(true);
                    $packages[] = $aliasPackage;
                }
            }
        }

        return new Pool($packages);
    }

    /**
     * @param string $packageName
     *
     * @return Pool
     */
    public function createPoolForPackage($packageName, LockArrayRepository $lockedRepo = null)
    {
        // TODO unify this with above in some simpler version without "request"?
        return $this->createPoolForPackages(array($packageName), $lockedRepo);
    }

    /**
     * @param string[] $packageNames
     *
     * @return Pool
     */
    public function createPoolForPackages($packageNames, LockArrayRepository $lockedRepo = null)
    {
        $request = new Request($lockedRepo);

        foreach ($packageNames as $packageName) {
            if (PlatformRepository::isPlatformPackage($packageName)) {
                throw new \LogicException('createPoolForPackage(s) can not be used for platform packages, as they are never loaded by the PoolBuilder which expects them to be fixed. Use createPoolWithAllPackages or pass in a proper request with the platform packages you need fixed in it.');
            }

            $request->requireName($packageName);
        }

        return $this->createPool($request, new NullIO());
    }

    /**
     * @param array[] $aliases
     * @phpstan-param list<array{package: string, version: string, alias: string, alias_normalized: string}> $aliases
     *
     * @return array<string, array<string, array{alias: string, alias_normalized: string}>>
     */
    private static function getRootAliasesPerPackage(array $aliases)
    {
        $normalizedAliases = array();

        foreach ($aliases as $alias) {
            $normalizedAliases[$alias['package']][$alias['version']] = array(
                'alias' => $alias['alias'],
                'alias_normalized' => $alias['alias_normalized'],
            );
        }

        return $normalizedAliases;
    }
}
