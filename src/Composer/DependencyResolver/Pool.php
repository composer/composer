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
use Composer\Package\Link;
use Composer\Package\LinkConstraint\LinkConstraintInterface;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Package\LinkConstraint\EmptyConstraint;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\CompositeRepository;
use Composer\Repository\ComposerRepository;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Repository\StreamableRepositoryInterface;
use Composer\Repository\PlatformRepository;

/**
 * A package pool contains repositories that provide packages.
 *
 * @author Nils Adermann <naderman@naderman.de>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Pool
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
    protected $acceptableStabilities;
    protected $stabilityFlags;
    protected $versionParser;
    protected $providerCache = array();
    protected $filterRequires;
    protected $id = 1;

    public function __construct($minimumStability = 'stable', array $stabilityFlags = array(), array $filterRequires = array())
    {
        $stabilities = BasePackage::$stabilities;
        $this->versionParser = new VersionParser;
        $this->acceptableStabilities = array();
        foreach (BasePackage::$stabilities as $stability => $value) {
            if ($value <= BasePackage::$stabilities[$minimumStability]) {
                $this->acceptableStabilities[$stability] = $value;
            }
        }
        $this->stabilityFlags = $stabilityFlags;
        $this->filterRequires = $filterRequires;
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
            } elseif ($repo instanceof StreamableRepositoryInterface) {
                foreach ($repo->getMinimalPackages() as $package) {
                    $name = $package['name'];
                    $version = $package['version'];
                    $stability = VersionParser::parseStability($version);

                    // collect names
                    $names = array(
                        $name => true,
                    );
                    if (isset($package['provide'])) {
                        foreach ($package['provide'] as $target => $constraint) {
                            $names[$target] = true;
                        }
                    }
                    if (isset($package['replace'])) {
                        foreach ($package['replace'] as $target => $constraint) {
                            $names[$target] = true;
                        }
                    }
                    $names = array_keys($names);

                    if ($exempt || $this->isPackageAcceptable($names, $stability)) {
                        $package['id'] = $this->id++;
                        $package['stability'] = $stability;
                        $this->packages[] = $package;

                        foreach ($names as $provided) {
                            $this->packageByName[$provided][$package['id']] = $this->packages[$this->id - 2];
                        }

                        // handle root package aliases
                        unset($rootAliasData);
                        if (isset($rootAliases[$name][$version])) {
                            $rootAliasData = $rootAliases[$name][$version];
                        } elseif (isset($package['alias_normalized']) && isset($rootAliases[$name][$package['alias_normalized']])) {
                            $rootAliasData = $rootAliases[$name][$package['alias_normalized']];
                        }

                        if (isset($rootAliasData)) {
                            $alias = $package;
                            unset($alias['raw']);
                            $alias['version'] = $rootAliasData['alias_normalized'];
                            $alias['alias'] = $rootAliasData['alias'];
                            $alias['alias_of'] = $package['id'];
                            $alias['id'] = $this->id++;
                            $alias['root_alias'] = true;
                            $this->packages[] = $alias;

                            foreach ($names as $provided) {
                                $this->packageByName[$provided][$alias['id']] = $this->packages[$this->id - 2];
                            }
                        }

                        // handle normal package aliases
                        if (isset($package['alias'])) {
                            $alias = $package;
                            unset($alias['raw']);
                            $alias['version'] = $package['alias_normalized'];
                            $alias['alias'] = $package['alias'];
                            $alias['alias_of'] = $package['id'];
                            $alias['id'] = $this->id++;
                            $this->packages[] = $alias;

                            foreach ($names as $provided) {
                                $this->packageByName[$provided][$alias['id']] = $this->packages[$this->id - 2];
                            }
                        }
                    }
                }
            } else {
                foreach ($repo->getPackages() as $package) {
                    $names = $package->getNames();
                    $stability = $package->getStability();
                    if ($exempt || $this->isPackageAcceptable($names, $stability)) {
                        $package->setId($this->id++);
                        $this->packages[] = $package;

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
    * @param int $id
    * @return PackageInterface
    */
    public function packageById($id)
    {
        return $this->ensurePackageIsLoaded($this->packages[$id - 1]);
    }

    /**
     * Searches all packages providing the given package name and match the constraint
     *
     * @param string                  $name       The package name to be searched for
     * @param LinkConstraintInterface $constraint A constraint that all returned
     *                                            packages must match or null to return all
     * @return array A set of packages
     */
    public function whatProvides($name, LinkConstraintInterface $constraint = null)
    {
        if (isset($this->providerCache[$name][(string) $constraint])) {
            return $this->providerCache[$name][(string) $constraint];
        }

        return $this->providerCache[$name][(string) $constraint] = $this->computeWhatProvides($name, $constraint);
    }

    /**
     * @see whatProvides
     */
    private function computeWhatProvides($name, $constraint)
    {
        $candidates = array();

        foreach ($this->providerRepos as $repo) {
            foreach ($repo->whatProvides($this, $name) as $candidate) {
                $candidates[] = $candidate;
                if ($candidate->getId() < 1) {
                    $candidate->setId($this->id++);
                    $this->packages[$this->id - 2] = $candidate;
                }
            }
        }

        if (isset($this->packageByName[$name])) {
            $candidates = array_merge($candidates, $this->packageByName[$name]);
        }

        $matches = $provideMatches = array();
        $nameMatch = false;

        foreach ($candidates as $candidate) {
            switch ($this->match($candidate, $name, $constraint)) {
                case self::MATCH_NONE:
                    break;

                case self::MATCH_NAME:
                    $nameMatch = true;
                    break;

                case self::MATCH:
                    $nameMatch = true;
                    $matches[] = $this->ensurePackageIsLoaded($candidate);
                    break;

                case self::MATCH_PROVIDE:
                    $provideMatches[] = $this->ensurePackageIsLoaded($candidate);
                    break;

                case self::MATCH_REPLACE:
                    $matches[] = $this->ensurePackageIsLoaded($candidate);
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

    public function literalToString($literal)
    {
        return ($literal > 0 ? '+' : '-') . $this->literalToPackage($literal);
    }

    public function literalToPrettyString($literal, $installedMap)
    {
        $package = $this->literalToPackage($literal);

        if (isset($installedMap[$package->getId()])) {
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

    private function ensurePackageIsLoaded($data)
    {
        if (is_array($data)) {
            if (isset($data['alias_of'])) {
                $aliasOf = $this->packageById($data['alias_of']);
                $package = $this->packages[$data['id'] - 1] = $data['repo']->loadAliasPackage($data, $aliasOf);
                $package->setRootPackageAlias(!empty($data['root_alias']));
            } else {
                $package = $this->packages[$data['id'] - 1] = $data['repo']->loadPackage($data);
            }

            foreach ($package->getNames() as $name) {
                $this->packageByName[$name][$data['id']] = $package;
            }
            $package->setId($data['id']);

            return $package;
        }

        return $data;
    }

    /**
     * Checks if the package matches the given constraint directly or through
     * provided or replaced packages
     *
     * @param  array|PackageInterface  $candidate
     * @param  string                  $name       Name of the package to be matched
     * @param  LinkConstraintInterface $constraint The constraint to verify
     * @return int                     One of the MATCH* constants of this class or 0 if there is no match
     */
    private function match($candidate, $name, LinkConstraintInterface $constraint = null)
    {
        // handle array packages
        if (is_array($candidate)) {
            $candidateName = $candidate['name'];
            $candidateVersion = $candidate['version'];
            $isDev = $candidate['stability'] === 'dev';
            $isAlias = isset($candidate['alias_of']);
        } else {
            // handle object packages
            $candidateName = $candidate->getName();
            $candidateVersion = $candidate->getVersion();
            $isDev = $candidate->getStability() === 'dev';
            $isAlias = $candidate instanceof AliasPackage;
        }

        if (!$isDev && !$isAlias && isset($this->filterRequires[$name])) {
            $requireFilter = $this->filterRequires[$name];
        } else {
            $requireFilter = new EmptyConstraint;
        }

        if ($candidateName === $name) {
            $pkgConstraint = new VersionConstraint('==', $candidateVersion);

            if ($constraint === null || $constraint->matches($pkgConstraint)) {
                return $requireFilter->matches($pkgConstraint) ? self::MATCH : self::MATCH_FILTERED;
            }

            return self::MATCH_NAME;
        }

        if (is_array($candidate)) {
            $provides = isset($candidate['provide'])
                ? $this->versionParser->parseLinks($candidateName, $candidateVersion, 'provides', $candidate['provide'])
                : array();
            $replaces = isset($candidate['replace'])
                ? $this->versionParser->parseLinks($candidateName, $candidateVersion, 'replaces', $candidate['replace'])
                : array();
        } else {
            $provides = $candidate->getProvides();
            $replaces = $candidate->getReplaces();
        }

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
