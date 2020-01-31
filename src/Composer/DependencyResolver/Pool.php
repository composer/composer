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

use Composer\Package\AliasPackage;
use Composer\Package\Version\VersionParser;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\EmptyConstraint;
use Composer\Package\PackageInterface;

/**
 * A package pool contains all packages for dependency resolution
 *
 * @author Nils Adermann <naderman@naderman.de>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Pool implements \Countable
{
    const MATCH_NONE = 0;
    const MATCH = 1;
    const MATCH_PROVIDE = 2;
    const MATCH_REPLACE = 3;

    protected $packages = array();
    protected $packageByName = array();
    protected $versionParser;
    protected $providerCache = array();
    protected $unacceptableFixedPackages;

    public function __construct(array $packages = array(), array $unacceptableFixedPackages = array())
    {
        $this->versionParser = new VersionParser;
        $this->setPackages($packages);
        $this->unacceptableFixedPackages = $unacceptableFixedPackages;
    }

    private function setPackages(array $packages)
    {
        $id = 1;

        foreach ($packages as $package) {
            $this->packages[] = $package;

            $package->id = $id++;

            foreach ($package->getNames() as $provided) {
                $this->packageByName[$provided][] = $package;
            }
        }
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
     * @return PackageInterface[]  A set of packages
     */
    public function whatProvides($name, ConstraintInterface $constraint = null)
    {
        $key = (string) $constraint;
        if (isset($this->providerCache[$name][$key])) {
            return $this->providerCache[$name][$key];
        }

        return $this->providerCache[$name][$key] = $this->computeWhatProvides($name, $constraint);
    }

    /**
     * @see whatProvides
     */
    private function computeWhatProvides($name, $constraint)
    {
        if (!isset($this->packageByName[$name])) {
            return array();
        }

        $matches = array();

        foreach ($this->packageByName[$name] as $candidate) {
            switch ($this->match($candidate, $name, $constraint)) {
                case self::MATCH_NONE:
                    break;

                case self::MATCH:
                case self::MATCH_REPLACE:
                case self::MATCH_PROVIDE:
                    $matches[] = $candidate;
                    break;

                default:
                    throw new \UnexpectedValueException('Unexpected match type');
            }
        }

        return $matches;
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

    /**
     * Checks if the package matches the given constraint directly or through
     * provided or replaced packages
     *
     * @param  PackageInterface       $candidate
     * @param  string                 $name       Name of the package to be matched
     * @param  ConstraintInterface    $constraint The constraint to verify
     * @return int                    One of the MATCH* constants of this class or 0 if there is no match
     */
    public function match($candidate, $name, ConstraintInterface $constraint = null)
    {
        $candidateName = $candidate->getName();
        $candidateVersion = $candidate->getVersion();

        if ($candidateName === $name) {
            $pkgConstraint = new Constraint('==', $candidateVersion);

            if ($constraint === null || $constraint->matches($pkgConstraint)) {
                return self::MATCH;
            }

            return self::MATCH_NONE;
        }

        $provides = $candidate->getProvides();
        $replaces = $candidate->getReplaces();

        // aliases create multiple replaces/provides for one target so they can not use the shortcut below
        if (isset($replaces[0]) || isset($provides[0])) {
            foreach ($provides as $link) {
                if ($link->getTarget() === $name && ($constraint === null || $constraint->matches($link->getConstraint()))) {
                    return self::MATCH_PROVIDE;
                }
            }

            foreach ($replaces as $link) {
                if ($link->getTarget() === $name && ($constraint === null || $constraint->matches($link->getConstraint()))) {
                    return self::MATCH_REPLACE;
                }
            }

            return self::MATCH_NONE;
        }

        if (isset($provides[$name]) && ($constraint === null || $constraint->matches($provides[$name]->getConstraint()))) {
            return self::MATCH_PROVIDE;
        }

        if (isset($replaces[$name]) && ($constraint === null || $constraint->matches($replaces[$name]->getConstraint()))) {
            return self::MATCH_REPLACE;
        }

        return self::MATCH_NONE;
    }

    public function isUnacceptableFixedPackage(PackageInterface $package)
    {
        return in_array($package, $this->unacceptableFixedPackages, true);
    }
}
