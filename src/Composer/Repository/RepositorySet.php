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
use Composer\Package\AliasPackage;
use Composer\Package\Version\VersionParser;
use Composer\Package\LinkConstraint\LinkConstraintInterface;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Package\LinkConstraint\EmptyConstraint;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\CompositeRepository;
use Composer\Repository\ComposerRepository;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Repository\PlatformRepository;
use Composer\Package\PackageInterface;

/**
 * The repository set manages access to repository data.
 *
 * @author Nils Adermann <naderman@naderman.de>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class RepositorySet
{
    protected $repositories = array();
    protected $providerRepos = array();
    protected $acceptableStabilities;
    protected $stabilityFlags;
    protected $versionParser;
    protected $filterRequires;

    public function __construct($minimumStability = 'stable', array $stabilityFlags = array(), array $filterRequires = array())
    {
        $this->versionParser = new VersionParser;
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
     * @param array               $rootAliases
     */
    public function addRepository(RepositoryInterface $repo, $rootAliases = array())
    {
        if ($repo instanceof CompositeRepository) {
            $repos = $repo->getRepositories();
        } else {
            $repos = array($repo);
        }

        foreach ($repos as $repo) {
            $this->repositories[] = $repo;
            $repo->setRootAliases($rootAliases);

            if ($repo instanceof ComposerRepository && $repo->hasProviders()) {
                $this->providerRepos[] = $repo;
            }
        }
    }

    public function getPriority(RepositoryInterface $repo)
    {
        $priority = array_search($repo, $this->repositories, true);

        if (false === $priority) {
            throw new \RuntimeException("Could not determine repository priority. The repository was not registered in the repository set.");
        }

        return -$priority;
    }

    /**
     * Creates a pool with all packages from all repositories. Does not work
     * with provider repositories.
     *
     * @return Pool
     */
    public function getCompletePool()
    {
        $names = array();
        foreach ($this->repositories as $repo) {
            foreach ($repo->getPackages() as $package) {
                $names[$package->getName()] = true;
            }
        }

        return $this->getPool(array_keys($names));
    }

    /**
     * Creates a pool with all given names and their requirements are loaded.
     *
     * @param array $packageNames A list of names that need to be available
     * @return Pool
     */
    public function getPool(array $packageNames)
    {
        $this->packageByExactName = array();
        foreach ($this->repositories as $repo) {
            if ((!$repo instanceof ComposerRepository) || !$repo->hasProviders()) {
                foreach ($repo->getPackages() as $package) {
                    $this->loadPackage(
                        $repo,
                        $package
                    );
                }
            }
        }

        $poolPackages = array();
        $poolPackageIndex = 0;
        $poolPackageToPriority = array();

        $loadedMaps = array_fill(0, count($this->repositories), array());
        do {
            $continue = false;
            $newPackageNames = array();

            foreach ($this->repositories as $priority => $repo) {
                $loadedCount = count($loadedMaps[$priority]);

                list($packages, $foundNames) = $this->getPackagesRecursively(
                    $repo,
                    $loadedMaps[$priority],
                    $packageNames
                );

                if (count($loadedMaps[$priority]) > $loadedCount) {
                    $continue = true;
                }

                // store matching priority for each package at its final index
                for ($oldIndex = $poolPackageIndex; $poolPackageIndex < $oldIndex + count($packages); $poolPackageIndex++) {
                    $poolPackageToPriority[$poolPackageIndex] = -$priority;
                }

                $poolPackages[] = $packages;
                $newPackageNames[] = $foundNames;
            }

            $packageNames = call_user_func_array('array_merge', $newPackageNames);
        } while ($continue);

        $this->packageByExactName = array();

        return new Pool(
            call_user_func_array('array_merge', $poolPackages),
            $poolPackageToPriority,
            $this->filterRequires
        );
    }

    protected function getPackagesRecursively($repo, &$loadedMap, $packageNames)
    {
        $workQueue = new \SplQueue;

        foreach ($packageNames as $packageName) {
            $workQueue->enqueue($packageName);
        }

        $allPackages = array(array());
        $foundNames = array();

        while (!$workQueue->isEmpty()) {
            $packageName = $workQueue->dequeue();

            if (isset($loadedMap[$packageName])) {
                continue;
            }

            $loadedMap[$packageName] = true;

            if ($repo instanceof ComposerRepository && $repo->hasProviders()) {
                $packages = $repo->loadName($packageName, array($this, 'isPackageAcceptable'));
                $loadedPackages = array(array());
                foreach ($packages as $package) {
                    $loadedPackages[] = $this->loadPackage($repo, $package);
                }
                $loadedPackages = call_user_func_array('array_merge', $loadedPackages);
            } else {
                $loadedPackages = isset($this->packageByExactName[spl_object_hash($repo)][$packageName]) ?
                    $this->packageByExactName[spl_object_hash($repo)][$packageName] : array();
            }

            foreach ($loadedPackages as $loadedPackage) {
                $requires = $loadedPackage->getRequires();
                foreach ($requires as $link) {
                    $dependency = $link->getTarget();
                    if (!isset($loadedMap[$dependency])) {
                        $foundNames[] = $link->getTarget();
                        $workQueue->enqueue($link->getTarget());
                    }
                }
            }
            $allPackages[] = $loadedPackages;
        }

        return array(call_user_func_array('array_merge', $allPackages), $foundNames);
    }

    private function loadPackage(RepositoryInterface $repo, PackageInterface $package)
    {
        $loaded = array();
        $rootAliases = $repo->getRootAliases();

        $names = $package->getNames();
        $stability = $package->getStability();

        if (!($repo instanceof PlatformRepository) &&
            !($repo instanceof InstalledRepositoryInterface) &&
            !$this->isPackageAcceptable($names, $stability)
        ) {
            return;
        }

        $loaded[] = $package;
        $this->packageByExactName[spl_object_hash($repo)][$package->getName()][] = $package;

        // handle root package aliases
        $name = $package->getName();
        if (isset($rootAliases[$name][$package->getVersion()])) {
            $alias = $rootAliases[$name][$package->getVersion()];
            if ($package instanceof AliasPackage) {
                $package = $package->getAliasOf();
            }
            $aliasPackage = new AliasPackage($package, $alias['alias_normalized'], $alias['alias']);
            $aliasPackage->setRootPackageAlias(true);

            $package->getRepository()->addPackage($aliasPackage);
            $loaded[] = $aliasPackage;
            $this->packageByExactName[spl_object_hash($repo)][$aliasPackage->getName()][] = $aliasPackage;
        }

        return $loaded;
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
     * Searches for all packages matching a name and optionally a version.
     *
     * @param string                                                          $name       package name
     * @param string|\Composer\Package\LinkConstraint\LinkConstraintInterface $constraint package version or version constraint to match against
     *
     * @return array
     */
    public function findPackages($name, $constraint = null)
    {
        $packages = array();
        foreach ($this->repositories as $repository) {
            /* @var $repository RepositoryInterface */
            $packages[] = $repository->findPackages($name, $constraint);
        }

        $candidates = $packages ? call_user_func_array('array_merge', $packages) : array();

        $result = array();
        foreach ($candidates as $candidate) {
            if ($this->isPackageAcceptable($candidate->getNames(), $candidate->getStability())) {
                $result[] = $candidate;
            }
        }

        return $result;
    }
}
