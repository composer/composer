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

use Composer\Package\LinkConstraint\LinkConstraintInterface;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\CompositeRepository;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Repository\PlatformRepository;

/**
 * A package pool contains repositories that provide packages.
 *
 * @author Nils Adermann <naderman@naderman.de>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Pool
{
    protected $repositories = array();
    protected $packages = array();
    protected $packageByName = array();
    protected $acceptableStabilities;

    public function __construct($minimumStability = 'dev')
    {
        $stabilities = array(
            'stable',
            'RC',
            'beta',
            'alpha',
            'dev',
        );

        $this->acceptableStabilities = array_flip(array_splice($stabilities, 0, array_search($minimumStability, $stabilities) + 1));
    }

    /**
     * Adds a repository and its packages to this package pool
     *
     * @param RepositoryInterface $repo A package repository
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

            $exempt = $repo instanceof PlatformRepository || $repo instanceof InstalledRepositoryInterface;
            foreach ($repo->getPackages() as $package) {
                if (!$exempt && !isset($this->acceptableStabilities[$package->getStability()])) {
                    continue;
                }

                $package->setId(count($this->packages) + 1);
                $this->packages[] = $package;

                foreach ($package->getNames() as $name) {
                    $this->packageByName[$name][] = $package;
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
     * @return array                              A set of packages
     */
    public function whatProvides($name, LinkConstraintInterface $constraint = null)
    {
        if (!isset($this->packageByName[$name])) {
            return array();
        }

        $candidates = $this->packageByName[$name];

        if (null === $constraint) {
            return $candidates;
        }

        $result = array();

        foreach ($candidates as $candidate) {
            if ($candidate->matches($name, $constraint)) {
                $result[] = $candidate;
            }
        }

        return $result;
    }
}
