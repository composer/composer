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
use Composer\Package\Version\VersionParser;

/**
 * A repository implementation that simply stores packages in an array
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class ArrayRepository implements RepositoryInterface
{
    protected $packages;

    /**
     * {@inheritDoc}
     */
    public function findPackage($name, $version)
    {
        // normalize version & name
        $versionParser = new VersionParser();
        $version = $versionParser->normalize($version);
        $name = strtolower($name);

        foreach ($this->getPackages() as $package) {
            if ($name === $package->getName() && $version === $package->getVersion()) {
                return $package;
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function findPackagesByName($name)
    {
        // normalize name
        $name = strtolower($name);

        return array_filter($this->getPackages(), function (PackageInterface $package) use ($name) {
            return $package->getName() === $name;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function hasPackage(PackageInterface $package)
    {
        $packageId = $package->getUniqueName();

        foreach ($this->getPackages() as $repoPackage) {
            if ($packageId === $repoPackage->getUniqueName()) {
                return true;
            }
        }

        return false;
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
     * Removes package from repository.
     *
     * @param   PackageInterface    $package    package instance
     */
    public function removePackage(PackageInterface $package)
    {
        $packageId = $package->getUniqueName();

        foreach ($this->getPackages() as $key => $repoPackage) {
            if ($packageId === $repoPackage->getUniqueName()) {
                array_splice($this->packages, $key, 1);

                return;
            }
        }
    }

    /**
     * {@inheritDoc}
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
