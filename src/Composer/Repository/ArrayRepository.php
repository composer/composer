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
        $packages = $this->getPackages();
        $uniqueName = $name.'-'.$version;

        return isset($packages[$uniqueName]) ? $packages[$uniqueName] : null;
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
    public function hasPackage(PackageInterface $package)
    {
        $packages = $this->getPackages();

        return isset($packages[$package->getUniqueName()]);
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

        $this->packages += array($package->getUniqueName() => $package);

        // create alias package on the fly if needed
        if ($package->getAlias()) {
            $alias = $this->createAliasPackage($package);
            if (!$this->hasPackage($alias)) {
                $this->addPackage($alias);
            }
        }
    }

    protected function createAliasPackage(PackageInterface $package, $alias = null, $prettyAlias = null)
    {
        return new AliasPackage($package, $alias ?: $package->getAlias(), $prettyAlias ?: $package->getPrettyAlias());
    }

    /**
     * Removes package from repository.
     *
     * @param PackageInterface $package package instance
     */
    public function removePackage(PackageInterface $package)
    {
        unset($this->packages[$package->getUniqueName()]);
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
