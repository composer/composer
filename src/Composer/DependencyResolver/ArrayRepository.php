<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\DependencyResolver;

/**
 * A repository implementation that simply stores packages in an array
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class ArrayRepository implements RepositoryInterface
{
    protected $packages = array();

    /**
     * Adds a new package to the repository
     *
     * @param Package $package
     */
    public function addPackage(Package $package)
    {
        $this->packages[$package->getId()] = $package;
    }

    /**
     * Returns all contained packages
     *
     * @return array All packages
     */
    public function getPackages()
    {
        return $this->packages;
    }

    /**
     * Checks if a package is contained in this repository
     *
     * @return bool
     */
    public function contains(Package $package)
    {
        return isset($this->packages[$package->getId()]);
    }

    /**
     * Returns the number of packages in this repository
     *
     * @return int Number of packages
     */
    public function count()
    {
        return count($this->packages);
    }
}
