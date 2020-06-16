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
use Composer\Package\AliasPackage;
use Composer\Package\Version\VersionParser;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\EmptyConstraint;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\CompositeRepository;
use Composer\Repository\ComposerRepository;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Repository\PlatformRepository;
use Composer\Package\PackageInterface;

/**
 * A package pool contains repositories that provide packages.
 *
 * @author Nils Adermann <naderman@naderman.de>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Pool implements \Countable
{
    const MATCH_NAME = -1;
    const MATCH_NONE = 0;
    const MATCH = 1;
    const MATCH_PROVIDE = 2;
    const MATCH_REPLACE = 3;
    const MATCH_FILTERED = 4;

    protected $repositories = array();
    protected $providerRepos = array();
    protected $packages = array();
    protected $packageByName = array();
    protected $packageByExactName = array();
    protected $acceptableStabilities;
    protected $stabilityFlags;
    protected $versionParser;
    protected $providerCache = array();
    protected $filterRequires;
    protected $whitelist = null; // TODO 2.0 rename to allowList
    protected $id = 1;

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

    public function setAllowList($allowList)
    {
        // call original method for BC
        $this->setWhitelist($allowList);
    }

    /**
     * @deprecated use setAllowList instead
     */
    public function setWhitelist($whitelist)
    {
        $this->whitelist = $whitelist;
        $this->providerCache = array();
    }

    /**
     * Adds a repository and its packages to this package pool
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

            $exempt = $repo instanceof PlatformRepository || $repo instanceof InstalledRepositoryInterface;

            if ($repo instanceof ComposerRepository && $repo->hasProviders()) {
                $this->providerRepos[] = $repo;
                $repo->setRootAliases($rootAliases);
                $repo->resetPackageIds();
            } else {
                foreach ($repo->getPackages() as $package) {
                    $names = $package->getNames();
                    $stability = $package->getStability();
                    if ($exempt || $this->isPackageAcceptable($names, $stability)) {
                        $package->setId($this->id++);
                        $this->packages[] = $package;
                        $this->packageByExactName[$package->getName()][$package->id] = $package;

                        foreach ($names as $provided) {
                            $this->packageByName[$provided][] = $package;
                        }

                        // handle root package aliases
                        $name = $package->getName();
                        if (isset($rootAliases[$name][$package->getVersion()])) {
                            $alias = $rootAliases[$name][$package->getVersion()];
                            if ($package instanceof AliasPackage) {
                                $package = $package->getAliasOf();
                            }
                            $aliasPackage = new AliasPackage($package, $alias['alias_normalized'], $alias['alias']);
                            $aliasPackage->setRootPackageAlias(true);
                            $aliasPackage->setId($this->id++);

                            $package->getRepository()->addPackage($aliasPackage);
                            $this->packages[] = $aliasPackage;
                            $this->packageByExactName[$aliasPackage->getName()][$aliasPackage->id] = $aliasPackage;

                            foreach ($aliasPackage->getNames() as $name) {
                                $this->packageByName[$name][] = $aliasPackage;
                            }
                        }
                    }
                }
            }
        }
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
     * Retrieves the package object for a given package id.
     *
     * @param  int              $id
     * @return PackageInterface
     */
    public function packageById($id)
    {
        return $this->packages[$id - 1];
    }

    /**
     * Returns how many packages have been loaded into the pool
     */
    public function count()
    {
        return count($this->packages);
    }

    /**
     * Searches all packages providing the given package name and match the constraint
     *
     * @param  string              $name          The package name to be searched for
     * @param  ConstraintInterface $constraint    A constraint that all returned
     *                                            packages must match or null to return all
     * @param  bool                $mustMatchName Whether the name of returned packages
     *                                            must match the given name
     * @param  bool                $bypassFilters If enabled, filterRequires and stability matching is ignored
     * @return PackageInterface[]  A set of packages
     */
    public function whatProvides($name, ConstraintInterface $constraint = null, $mustMatchName = false, $bypassFilters = false)
    {
        if ($bypassFilters) {
            return $this->computeWhatProvides($name, $constraint, $mustMatchName, true);
        }

        $key = ((int) $mustMatchName).$constraint;
        if (isset($this->providerCache[$name][$key])) {
            return $this->providerCache[$name][$key];
        }

        return $this->providerCache[$name][$key] = $this->computeWhatProvides($name, $constraint, $mustMatchName, $bypassFilters);
    }

    /**
     * @see whatProvides
     */
    private function computeWhatProvides($name, $constraint, $mustMatchName = false, $bypassFilters = false)
    {
        $candidates = array();

        foreach ($this->providerRepos as $repo) {
            foreach ($repo->whatProvides($this, $name, $bypassFilters) as $candidate) {
                $candidates[] = $candidate;
                if ($candidate->id < 1) {
                    $candidate->setId($this->id++);
                    $this->packages[$this->id - 2] = $candidate;
                }
            }
        }

        if ($mustMatchName) {
            $candidates = array_filter($candidates, function ($candidate) use ($name) {
                return $candidate->getName() == $name;
            });
            if (isset($this->packageByExactName[$name])) {
                $candidates = array_merge($candidates, $this->packageByExactName[$name]);
            }
        } elseif (isset($this->packageByName[$name])) {
            $candidates = array_merge($candidates, $this->packageByName[$name]);
        }

        $matches = $provideMatches = array();
        $nameMatch = false;

        foreach ($candidates as $candidate) {
            $aliasOfCandidate = null;

            // alias packages are not white listed, make sure that the package
            // being aliased is white listed
            if ($candidate instanceof AliasPackage) {
                $aliasOfCandidate = $candidate->getAliasOf();
            }

            if ($this->whitelist !== null && !$bypassFilters && (
                (!($candidate instanceof AliasPackage) && !isset($this->whitelist[$candidate->id])) ||
                ($candidate instanceof AliasPackage && !isset($this->whitelist[$aliasOfCandidate->id]))
            )) {
                continue;
            }
            switch ($this->match($candidate, $name, $constraint, $bypassFilters)) {
                case self::MATCH_NONE:
                    break;

                case self::MATCH_NAME:
                    $nameMatch = true;
                    break;

                case self::MATCH:
                    $nameMatch = true;
                    $matches[] = $candidate;
                    break;

                case self::MATCH_PROVIDE:
                    $provideMatches[] = $candidate;
                    break;

                case self::MATCH_REPLACE:
                    $matches[] = $candidate;
                    break;

                case self::MATCH_FILTERED:
                    break;

                default:
                    throw new \UnexpectedValueException('Unexpected match type');
            }
        }

        // if a package with the required name exists, we ignore providers
        if ($nameMatch) {
            return $matches;
        }

        return array_merge($matches, $provideMatches);
    }

    public function literalToPackage($literal)
    {
        $packageId = abs($literal);

        return $this->packageById($packageId);
    }

    public function literalToPrettyString($literal, $installedMap)
    {
        $package = $this->literalToPackage($literal);

        if (isset($installedMap[$package->id])) {
            $prefix = ($literal > 0 ? 'keep' : 'remove');
        } else {
            $prefix = ($literal > 0 ? 'install' : 'don\'t install');
        }

        return $prefix.' '.$package->getPrettyString();
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
     * Checks if the package matches the given constraint directly or through
     * provided or replaced packages
     *
     * @param  PackageInterface       $candidate
     * @param  string                 $name       Name of the package to be matched
     * @param  ConstraintInterface    $constraint The constraint to verify
     * @return int                    One of the MATCH* constants of this class or 0 if there is no match
     */
    public function match($candidate, $name, ConstraintInterface $constraint = null, $bypassFilters)
    {
        $candidateName = $candidate->getName();
        $candidateVersion = $candidate->getVersion();
        $isDev = $candidate->getStability() === 'dev';
        $isAlias = $candidate instanceof AliasPackage;

        if (!$bypassFilters && !$isDev && !$isAlias && isset($this->filterRequires[$name])) {
            $requireFilter = $this->filterRequires[$name];
        } else {
            $requireFilter = new EmptyConstraint;
        }

        if ($candidateName === $name) {
            $pkgConstraint = new Constraint('==', $candidateVersion);

            if ($constraint === null || $constraint->matches($pkgConstraint)) {
                return $requireFilter->matches($pkgConstraint) ? self::MATCH : self::MATCH_FILTERED;
            }

            return self::MATCH_NAME;
        }

        $provides = $candidate->getProvides();
        $replaces = $candidate->getReplaces();

        // aliases create multiple replaces/provides for one target so they can not use the shortcut below
        if (isset($replaces[0]) || isset($provides[0])) {
            foreach ($provides as $link) {
                if ($link->getTarget() === $name && ($constraint === null || $constraint->matches($link->getConstraint()))) {
                    return $requireFilter->matches($link->getConstraint()) ? self::MATCH_PROVIDE : self::MATCH_FILTERED;
                }
            }

            foreach ($replaces as $link) {
                if ($link->getTarget() === $name && ($constraint === null || $constraint->matches($link->getConstraint()))) {
                    return $requireFilter->matches($link->getConstraint()) ? self::MATCH_REPLACE : self::MATCH_FILTERED;
                }
            }

            return self::MATCH_NONE;
        }

        if (isset($provides[$name]) && ($constraint === null || $constraint->matches($provides[$name]->getConstraint()))) {
            return $requireFilter->matches($provides[$name]->getConstraint()) ? self::MATCH_PROVIDE : self::MATCH_FILTERED;
        }

        if (isset($replaces[$name]) && ($constraint === null || $constraint->matches($replaces[$name]->getConstraint()))) {
            return $requireFilter->matches($replaces[$name]->getConstraint()) ? self::MATCH_REPLACE : self::MATCH_FILTERED;
        }

        return self::MATCH_NONE;
    }
}
