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
 * Writable repository interface.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
interface WritableRepositoryInterface extends RepositoryInterface
{
    /**
     * Writes repository (f.e. to the disc).
     */
    public function write();

    /**
     * Adds package to the repository.
     *
     * @param PackageInterface $package package instance
     */
    public function addPackage(PackageInterface $package);

    /**
     * Removes package from the repository.
     *
     * @param PackageInterface $package package instance
     */
    public function removePackage(PackageInterface $package);

    /**
     * Get unique packages (at most one package of each name), with aliases resolved and removed.
     *
     * @return PackageInterface[]
     */
    public function getCanonicalPackages();

    /**
     * Forces a reload of all packages.
     */
    public function reload();
}
