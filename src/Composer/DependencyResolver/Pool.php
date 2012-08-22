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
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Link;
use Composer\Package\LinkConstraint\LinkConstraintInterface;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\CompositeRepository;
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

    protected $repositories = array();
    protected $packages = array();
    protected $packageByName = array();
    protected $acceptableStabilities;
    protected $stabilityFlags;
    protected $loader;
    protected $versionParser;

    public function __construct($minimumStability = 'stable', array $stabilityFlags = array())
    {
        $stabilities = BasePackage::$stabilities;
        $this->loader = new ArrayLoader;
        $this->versionParser = new VersionParser;
        $this->acceptableStabilities = array();
        foreach (BasePackage::$stabilities as $stability => $value) {
            if ($value <= BasePackage::$stabilities[$minimumStability]) {
                $this->acceptableStabilities[$stability] = $value;
            }
        }
        $this->stabilityFlags = $stabilityFlags;
    }

    /**
     * Adds a repository and its packages to this package pool
     *
     * @param RepositoryInterface $repo A package repository
     * @param array               $aliases
     */
    public function addRepository(RepositoryInterface $repo, $aliases = array())
    {
        if ($repo instanceof CompositeRepository) {
            $repos = $repo->getRepositories();
        } else {
            $repos = array($repo);
        }

        $id = count($this->packages) + 1;
        foreach ($repos as $repo) {
            $this->repositories[] = $repo;

            $exempt = $repo instanceof PlatformRepository || $repo instanceof InstalledRepositoryInterface;
            if ($repo instanceof StreamableRepositoryInterface) {
                foreach ($repo->getMinimalPackages() as $package) {
                    $name = $package['name'];
                    $version = $package['version'];
                    $stability = VersionParser::parseStability($version);
                    if (
                        // always allow exempt repos
                        $exempt
                        // allow if package matches the global stability requirement and has no exception
                        || (!isset($this->stabilityFlags[$name])
                            && isset($this->acceptableStabilities[$stability]))
                        // allow if package matches the package-specific stability flag
                        || (isset($this->stabilityFlags[$name])
                            && BasePackage::$stabilities[$stability] <= $this->stabilityFlags[$name]
                        )
                    ) {
                        $package['id'] = $id++;
                        $this->packages[] = $package;

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

                        foreach (array_keys($names) as $name) {
                            $this->packageByName[$name][] =& $this->packages[$id-2];
                        }

                        // handle root package aliases
                        if (isset($aliases[$name][$version])) {
                            $alias = $package;
                            $alias['version'] = $aliases[$name][$version]['alias_normalized'];
                            $alias['alias'] = $aliases[$name][$version]['alias'];
                            $alias['alias_of'] = $package['id'];
                            $alias['id'] = $id++;
                            $alias['root_alias'] = true;
                            $this->packages[] = $alias;

                            foreach (array_keys($names) as $name) {
                                $this->packageByName[$name][] =& $this->packages[$id-2];
                            }
                        }

                        // handle normal package aliases
                        if (isset($package['alias'])) {
                            $alias = $package;
                            $alias['version'] = $package['alias_normalized'];
                            $alias['alias'] = $package['alias'];
                            $alias['alias_of'] = $package['id'];
                            $alias['id'] = $id++;
                            $this->packages[] = $alias;

                            foreach (array_keys($names) as $name) {
                                $this->packageByName[$name][] =& $this->packages[$id-2];
                            }
                        }
                    }
                }
            } else {
                foreach ($repo->getPackages() as $package) {
                    $name = $package->getName();
                    $stability = $package->getStability();
                    if (
                        // always allow exempt repos
                        $exempt
                        // allow if package matches the global stability requirement and has no exception
                        || (!isset($this->stabilityFlags[$name])
                            && isset($this->acceptableStabilities[$stability]))
                        // allow if package matches the package-specific stability flag
                        || (isset($this->stabilityFlags[$name])
                            && BasePackage::$stabilities[$stability] <= $this->stabilityFlags[$name]
                        )
                    ) {
                        $package->setId($id++);
                        $this->packages[] = $package;

                        foreach ($package->getNames() as $name) {
                            $this->packageByName[$name][] = $package;
                        }

                        // handle root package aliases
                        if (isset($aliases[$name][$package->getVersion()])) {
                            $alias = $aliases[$name][$package->getVersion()];
                            $package->setAlias($alias['alias_normalized']);
                            $package->setPrettyAlias($alias['alias']);
                            $package->getRepository()->addPackage($aliasPackage = new AliasPackage($package, $alias['alias_normalized'], $alias['alias']));
                            $aliasPackage->setRootPackageAlias(true);
                            $aliasPackage->setId($id++);

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
        $this->ensurePackageIsLoaded($this->packages[$id - 1]);

        return $this->packages[$id - 1];
    }

    /**
    * Retrieves the highest id assigned to a package in this pool
    *
    * @return int Highest package id
    */
    public function getMaxId()
    {
        return count($this->packages);
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
        if (!isset($this->packageByName[$name])) {
            return array();
        }

        $candidates = $this->packageByName[$name];

        if (null === $constraint) {
            foreach ($candidates as $key => $candidate) {
                $candidates[$key] = $this->ensurePackageIsLoaded($candidate);
            }

            return $candidates;
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

    private function ensurePackageIsLoaded($data)
    {
        if (is_array($data)) {
            if (isset($data['alias_of'])) {
                // TODO move to $repo->loadAliasPackage?
                $aliasOf = $this->packageById($data['alias_of']);
                $rootAlias = !empty($data['root_alias']);
                $package = $this->packages[$data['id'] - 1] = new AliasPackage($aliasOf, $data['version'], $data['alias']);
                $package->setId($data['id']);
                $package->setRootPackageAlias($rootAlias);

                return $package;
            }

            $package = $this->packages[$data['id'] - 1] = $data['repo']->loadPackage($data, $data['id']);

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
    private function match($candidate, $name, LinkConstraintInterface $constraint)
    {
        if (is_array($candidate)) {
            $candidateName = $candidate['name'];
            $candidateVersion = $candidate['version'];
            foreach (array('provides', 'replaces') as $linkType) {
                $$linkType = isset($candidate[rtrim($linkType, 's')]) ? $candidate[rtrim($linkType, 's')] : array();
                foreach ($$linkType as $target => $constraintDef) {
                    if ('self.version' === $constraintDef) {
                        $parsedConstraint = $this->versionParser->parseConstraints($candidateVersion);
                    } else {
                        $parsedConstraint = $this->versionParser->parseConstraints($constraintDef);
                    }
                    ${$linkType}[$target] = new Link($candidateName, $target, $parsedConstraint, $linkType, $constraintDef);
                }
            }
        } else {
            $candidateName = $candidate->getName();
            $candidateVersion = $candidate->getVersion();
            $provides = $candidate->getProvides();
            $replaces = $candidate->getReplaces();
        }

        if ($candidateName === $name) {
            return $constraint->matches(new VersionConstraint('==', $candidateVersion)) ? self::MATCH : self::MATCH_NAME;
        }

        foreach ($provides as $link) {
            if ($link->getTarget() === $name && $constraint->matches($link->getConstraint())) {
                return self::MATCH_PROVIDE;
            }
        }

        foreach ($replaces as $link) {
            if ($link->getTarget() === $name && $constraint->matches($link->getConstraint())) {
                return self::MATCH_REPLACE;
            }
        }

        return self::MATCH_NONE;
    }

}
