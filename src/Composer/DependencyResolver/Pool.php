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

    protected $packages = array();
    protected $packageByName = array();
    protected $packageByExactName = array();
    protected $packageToPriority = array();
    protected $filterRequires;

    public function __construct(array $packages, array $packageToPriority, array $filterRequires = array())
    {
        $this->packages = $packages;
        $this->packageToPriority = $packageToPriority;

        foreach ($this->packages as $id => $package) {
            $package->setId($id + 1);
            $this->packageByExactName[$package->getName()][$id + 1] = $package;
            foreach ($package->getNames() as $name) {
                $this->packageByName[$name][$id + 1] = $package;
            }
        }

        $this->filterRequires = $filterRequires;
    }

    /**
    * Retrieves the package object for a given package id.
    *
    * @param int $id
    * @return PackageInterface
    */
    public function packageById($id)
    {
        return $this->packages[$id - 1];
    }

    /**
     * Retrieves the package's priority in this pool.
     *
     * @param int $id
     * @return int Priority
     */
    public function getPriority($id)
    {
        return $this->packageToPriority[$id - 1];
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
     * @param  string                  $name          The package name to be searched for
     * @param  LinkConstraintInterface $constraint    A constraint that all returned
     *                                                packages must match or null to return all
     * @param  bool                    $mustMatchName Whether the name of returned packages
     *                                                must match the given name
     * @return PackageInterface[]      A set of packages
     */
    public function whatProvides($name, LinkConstraintInterface $constraint = null, $mustMatchName = false)
    {
        $key = ((int) $mustMatchName).$constraint;
        if (isset($this->providerCache[$name][$key])) {
            return $this->providerCache[$name][$key];
        }

        return $this->providerCache[$name][$key] = $this->computeWhatProvides($name, $constraint, $mustMatchName);
    }

    /**
     * @see whatProvides
     */
    private function computeWhatProvides($name, $constraint, $mustMatchName = false)
    {
        $candidates = array();

        if ($mustMatchName) {
            if (isset($this->packageByExactName[$name])) {
                $candidates = $this->packageByExactName[$name];
            }
        } elseif (isset($this->packageByName[$name])) {
            $candidates = $this->packageByName[$name];
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

    public function literalToString($literal)
    {
        return ($literal > 0 ? '+' : '-') . $this->literalToPackage($literal);
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
        $candidateName = $candidate->getName();
        $candidateVersion = $candidate->getVersion();
        $isDev = $candidate->getStability() === 'dev';
        $isAlias = $candidate instanceof AliasPackage;

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
