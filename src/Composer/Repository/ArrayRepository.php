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

use Composer\Package\PackageInterface;

/**
 * A repository implementation that simply stores packages in an array
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class ArrayRepository implements RepositoryInterface
{
    protected $packages;

    static public function supports($type, $name = '', $url = '')
    {
        return 'array' === strtolower($type);
    }

    static public function create($type, $name = '', $url = '')
    {
        return new static();
    }

    /**
     * Adds a new package to the repository
     *
     * @param PackageInterface $package
     */
    public function addPackage(PackageInterface $package)
    {
        if (null === $this->packages) {
            $this->initialize();
        }
        $package->setRepository($this);
        $this->packages[] = $package;
    }

    /**
     * Returns all contained packages
     *
     * @return array All packages
     */
    public function getPackages()
    {
        if (null === $this->packages) {
            $this->initialize();
        }
        return $this->packages;
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

    /**
     * Initializes the packages array. Mostly meant as an extension point.
     */
    protected function initialize()
    {
        $this->packages = array();
    }
}
