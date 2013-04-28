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

use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Package\CompletePackageInterface;
use Composer\Package\Version\VersionParser;

/**
 * A repository implementation that simply stores packages in an array
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class ArrayRepository implements RepositoryInterface
{
    protected $packages;

    public function __construct(array $packages = array())
    {
        foreach ($packages as $package) {
            $this->addPackage($package);
        }
    }

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
    public function findPackages($name, $version = null)
    {
        // normalize name
        $name = strtolower($name);

        // normalize version
        if (null !== $version) {
            $versionParser = new VersionParser();
            $version = $versionParser->normalize($version);
        }

        $packages = array();

        foreach ($this->getPackages() as $package) {
            if ($package->getName() === $name && (null === $version || $version === $package->getVersion())) {
                $packages[] = $package;
            }
        }

        return $packages;
    }

    /**
     * {@inheritDoc}
     */
    public function search($query, $mode = 0)
    {
        $regex = '{(?:'.implode('|', preg_split('{\s+}', $query)).')}i';

        $matches = array();
        foreach ($this->getPackages() as $package) {
            $name = $package->getName();
            if (isset($matches[$name])) {
                continue;
            }
            if (preg_match($regex, $name)
                || ($mode === self::SEARCH_FULLTEXT && $package instanceof CompletePackageInterface && preg_match($regex, implode(' ', (array) $package->getKeywords()) . ' ' . $package->getDescription()))
            ) {
                $matches[$name] = array(
                    'name' => $package->getPrettyName(),
                    'description' => $package->getDescription(),
                );
            }
        }

        return $matches;
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

        if ($package instanceof AliasPackage) {
            $aliasedPackage = $package->getAliasOf();
            if (null === $aliasedPackage->getRepository()) {
                $this->addPackage($aliasedPackage);
            }
        }
    }

    protected function createAliasPackage(PackageInterface $package, $alias, $prettyAlias)
    {
        return new AliasPackage($package instanceof AliasPackage ? $package->getAliasOf() : $package, $alias, $prettyAlias);
    }

    /**
     * Removes package from repository.
     *
     * @param PackageInterface $package package instance
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
