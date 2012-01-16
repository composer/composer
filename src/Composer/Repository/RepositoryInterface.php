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
 * Repository interface.
 *
 * @author Nils Adermann <naderman@naderman.de>
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
interface RepositoryInterface extends \Countable
{
    /**
     * Checks if specified package registered (installed).
     *
     * @param   PackageInterface    $package    package instance
     *
     * @return  Boolean
     */
    function hasPackage(PackageInterface $package);

    /**
     * Searches for a package by it's name and version (if has one).
     *
     * @param   string  $name       package name
     * @param   string  $version    package version
     *
     * @return  PackageInterface|null
     */
    function findPackage($name, $version);

    /**
     * Searches for packages by it's name .
     *
     * @param   string  $name       package name
     *
     * @return  array
     */
    function findPackagesByName($name);

    /**
     * Returns list of registered packages.
     *
     * @return  array
     */
    function getPackages();
}
