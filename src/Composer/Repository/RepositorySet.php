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

namespace Composer\Repository;

use Composer\DependencyResolver\PoolOptimizer;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\PoolBuilder;
use Composer\DependencyResolver\Request;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Advisory\SecurityAdvisory;
use Composer\Advisory\PartialSecurityAdvisory;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Package\BasePackage;
use Composer\Package\AliasPackage;
use Composer\Package\CompleteAliasPackage;
use Composer\Package\CompletePackage;
use Composer\Package\PackageInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Package\Version\StabilityFilter;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Semver\Constraint\MultiConstraint;

/**
 * @author Nils Adermann <naderman@naderman.de>
 *
 * @see RepositoryUtils for ways to work with single repos
 */
class RepositorySet
{
    /**
     * Packages are returned even though their stability does not match the required stability
     */
    public const ALLOW_UNACCEPTABLE_STABILITIES = 1;
    /**
     * Packages will be looked up in all repositories, even after they have been found in a higher prio one
     */
    public const ALLOW_SHADOWED_REPOSITORIES = 2;

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
    private $repositories = [];

    /**
     * @var int[] array of stability => BasePackage::STABILITY_* value
     * @phpstan-var array<key-of<BasePackage::STABILITIES>, BasePackage::STABILITY_*>
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

    /**
     * @var array<string, ConstraintInterface>
     */
    private $temporaryConstraints;

    /** @var bool */
    private $locked = false;
    /** @var bool */
    private $allowInstalledRepositories = false;

    /**
     * In most cases if you are looking to use this class as a way to find packages from repositories
     * passing minimumStability is all you need to worry about. The rest is for advanced pool creation including
     * aliases, pinned references and other special cases.
     *
     * @param key-of<BasePackage::STABILITIES> $minimumStability
     * @param int[]  $stabilityFlags   an array of package name => BasePackage::STABILITY_* value
     * @phpstan-param array<string, BasePackage::STABILITY_*> $stabilityFlags
     * @param array[] $rootAliases
     * @phpstan-param list<array{package: string, version: string, alias: string, alias_normalized: string}> $rootAliases
     * @param string[] $rootReferences an array of package name => source reference
     * @phpstan-param array<string, string> $rootReferences
     * @param ConstraintInterface[] $rootRequires an array of package name => constraint from the root package
     * @phpstan-param array<string, ConstraintInterface> $rootRequires
     * @param array<string, ConstraintInterface> $temporaryConstraints Runtime temporary constraints that will be used to filter packages
     */
    public function __construct(string $minimumStability = 'stable', array $stabilityFlags = [], array $rootAliases = [], array $rootReferences = [], array $rootRequires = [], array $temporaryConstraints = [])
    {
        $this->rootAliases = self::getRootAliasesPerPackage($rootAliases);
        $this->rootReferences = $rootReferences;

        $this->acceptableStabilities = [];
        foreach (BasePackage::STABILITIES as $stability => $value) {
            if ($value <= BasePackage::STABILITIES[$minimumStability]) {
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

        $this->temporaryConstraints = $temporaryConstraints;
    }

    public function allowInstalledRepositories(bool $allow = true): void
    {
        $this->allowInstalledRepositories = $allow;
    }

    /**
     * @return ConstraintInterface[] an array of package name => constraint from the root package, platform requirements excluded
     * @phpstan-return array<string, ConstraintInterface>
     */
    public function getRootRequires(): array
    {
        return $this->rootRequires;
    }

    /**
     * @return array<string, ConstraintInterface> Runtime temporary constraints that will be used to filter packages
     */
    public function getTemporaryConstraints(): array
    {
        return $this->temporaryConstraints;
    }

    /**
     * Adds a repository to this repository set
     *
     * The first repos added have a higher priority. As soon as a package is found in any
     * repository the search for that package ends, and following repos will not be consulted.
     *
     * @param RepositoryInterface $repo A package repository
     */
    public function addRepository(RepositoryInterface $repo): void
    {
        if ($this->locked) {
            throw new \RuntimeException("Pool has already been created from this repository set, it cannot be modified anymore.");
        }

        if ($repo instanceof CompositeRepository) {
            $repos = $repo->getRepositories();
        } else {
            $repos = [$repo];
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
     * @param  int                      $flags      any of the ALLOW_* constants from this class to tweak what is returned
     * @return BasePackage[]
     */
    public function findPackages(string $name, ?ConstraintInterface $constraint = null, int $flags = 0): array
    {
        $ignoreStability = ($flags & self::ALLOW_UNACCEPTABLE_STABILITIES) !== 0;
        $loadFromAllRepos = ($flags & self::ALLOW_SHADOWED_REPOSITORIES) !== 0;

        $packages = [];
        if ($loadFromAllRepos) {
            foreach ($this->repositories as $repository) {
                $packages[] = $repository->findPackages($name, $constraint) ?: [];
            }
        } else {
            foreach ($this->repositories as $repository) {
                $result = $repository->loadPackages([$name => $constraint], $ignoreStability ? BasePackage::STABILITIES : $this->acceptableStabilities, $ignoreStability ? [] : $this->stabilityFlags);

                $packages[] = $result['packages'];
                foreach ($result['namesFound'] as $nameFound) {
                    // avoid loading the same package again from other repositories once it has been found
                    if ($name === $nameFound) {
                        break 2;
                    }
                }
            }
        }

        $candidates = $packages ? array_merge(...$packages) : [];

        // when using loadPackages above (!$loadFromAllRepos) the repos already filter for stability so no need to do it again
        if ($ignoreStability || !$loadFromAllRepos) {
            return $candidates;
        }

        $result = [];
        foreach ($candidates as $candidate) {
            if ($this->isPackageAcceptable($candidate->getNames(), $candidate->getStability())) {
                $result[] = $candidate;
            }
        }

        return $result;
    }

    /**
     * @param string[] $packageNames
     * @return ($allowPartialAdvisories is true ? array<string, array<PartialSecurityAdvisory|SecurityAdvisory>> : array<string, array<SecurityAdvisory>>)
     */
    public function getSecurityAdvisories(array $packageNames, bool $allowPartialAdvisories = false): array
    {
        $map = [];
        foreach ($packageNames as $name) {
            $map[$name] = new MatchAllConstraint();
        }

        return $this->getSecurityAdvisoriesForConstraints($map, $allowPartialAdvisories);
    }

    /**
     * @param PackageInterface[] $packages
     * @return ($allowPartialAdvisories is true ? array<string, array<PartialSecurityAdvisory|SecurityAdvisory>> : array<string, array<SecurityAdvisory>>)
     */
    public function getMatchingSecurityAdvisories(array $packages, bool $allowPartialAdvisories = false): array
    {
        $map = [];
        foreach ($packages as $package) {
            // ignore root alias versions as they are not actual package versions and should not matter when it comes to vulnerabilities
            if ($package instanceof AliasPackage && $package->isRootPackageAlias()) {
                continue;
            }
            if (isset($map[$package->getName()])) {
                $map[$package->getName()] = new MultiConstraint([new Constraint('=', $package->getVersion()), $map[$package->getName()]], false);
            } else {
                $map[$package->getName()] = new Constraint('=', $package->getVersion());
            }
        }

        return $this->getSecurityAdvisoriesForConstraints($map, $allowPartialAdvisories);
    }

    /**
     * @param array<string, ConstraintInterface> $packageConstraintMap
     * @return ($allowPartialAdvisories is true ? array<string, array<PartialSecurityAdvisory|SecurityAdvisory>> : array<string, array<SecurityAdvisory>>)
     */
    private function getSecurityAdvisoriesForConstraints(array $packageConstraintMap, bool $allowPartialAdvisories): array
    {
        $repoAdvisories = [];
        foreach ($this->repositories as $repository) {
            if (!$repository instanceof AdvisoryProviderInterface || !$repository->hasSecurityAdvisories()) {
                continue;
            }

            $repoAdvisories[] = $repository->getSecurityAdvisories($packageConstraintMap, $allowPartialAdvisories)['advisories'];
        }

        $advisories = array_merge_recursive([], ...$repoAdvisories);
        ksort($advisories);

        return $advisories;
    }

    /**
     * @return array[] an array with the provider name as key and value of array('name' => '...', 'description' => '...', 'type' => '...')
     * @phpstan-return array<string, array{name: string, description: string|null, type: string}>
     */
    public function getProviders(string $packageName): array
    {
        $providers = [];
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
     * @param string[] $names
     * @param key-of<BasePackage::STABILITIES> $stability one of 'stable', 'RC', 'beta', 'alpha' or 'dev'
     */
    public function isPackageAcceptable(array $names, string $stability): bool
    {
        return StabilityFilter::isPackageAcceptable($this->acceptableStabilities, $this->stabilityFlags, $names, $stability);
    }

    /**
     * Create a pool for dependency resolution from the packages in this repository set.
     *
     * @param list<string>      $ignoredTypes Packages of those types are ignored
     * @param list<string>|null $allowedTypes Only packages of those types are allowed if set to non-null
     */
    public function createPool(Request $request, IOInterface $io, ?EventDispatcher $eventDispatcher = null, ?PoolOptimizer $poolOptimizer = null, array $ignoredTypes = [], ?array $allowedTypes = null): Pool
    {
        $poolBuilder = new PoolBuilder($this->acceptableStabilities, $this->stabilityFlags, $this->rootAliases, $this->rootReferences, $io, $eventDispatcher, $poolOptimizer, $this->temporaryConstraints);
        $poolBuilder->setIgnoredTypes($ignoredTypes);
        $poolBuilder->setAllowedTypes($allowedTypes);

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
     */
    public function createPoolWithAllPackages(): Pool
    {
        foreach ($this->repositories as $repo) {
            if (($repo instanceof InstalledRepositoryInterface || $repo instanceof InstalledRepository) && !$this->allowInstalledRepositories) {
                throw new \LogicException('The pool can not accept packages from an installed repository');
            }
        }

        $this->locked = true;

        $packages = [];
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

    public function createPoolForPackage(string $packageName, ?LockArrayRepository $lockedRepo = null): Pool
    {
        // TODO unify this with above in some simpler version without "request"?
        return $this->createPoolForPackages([$packageName], $lockedRepo);
    }

    /**
     * @param string[] $packageNames
     */
    public function createPoolForPackages(array $packageNames, ?LockArrayRepository $lockedRepo = null): Pool
    {
        $request = new Request($lockedRepo);

        $allowedPackages = [];
        foreach ($packageNames as $packageName) {
            if (PlatformRepository::isPlatformPackage($packageName)) {
                throw new \LogicException('createPoolForPackage(s) can not be used for platform packages, as they are never loaded by the PoolBuilder which expects them to be fixed. Use createPoolWithAllPackages or pass in a proper request with the platform packages you need fixed in it.');
            }

            $request->requireName($packageName);
            $allowedPackages[] = strtolower($packageName);
        }

        if (count($allowedPackages) > 0) {
            $request->restrictPackages($allowedPackages);
        }

        return $this->createPool($request, new NullIO());
    }

    /**
     * @param array[] $aliases
     * @phpstan-param list<array{package: string, version: string, alias: string, alias_normalized: string}> $aliases
     *
     * @return array<string, array<string, array{alias: string, alias_normalized: string}>>
     */
    private static function getRootAliasesPerPackage(array $aliases): array
    {
        $normalizedAliases = [];

        foreach ($aliases as $alias) {
            $normalizedAliases[$alias['package']][$alias['version']] = [
                'alias' => $alias['alias'],
                'alias_normalized' => $alias['alias_normalized'],
            ];
        }

        return $normalizedAliases;
    }
}
