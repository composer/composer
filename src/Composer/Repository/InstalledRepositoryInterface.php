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
 * Installable repository interface.
 *
 * Just used to tag installed repositories so the base classes can act differently on Alias packages
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Martin Hasoň <martin.hason@gmail.com>
 */
interface InstalledRepositoryInterface extends WritableRepositoryInterface
{
    /**
     * Returns an installation path of a package.
     *
     * @param PackageInterface $package The package instance
     *
     * @return string|null
     *
     * @throws \InvalidArgumenException if a package is not in the repository
     */
    public function getInstallPath(PackageInterface $package);

    /**
     * Sets an installation path of a package.
     *
     * @param PackageInterface $package     The package instance
     * @param string|null      $path        The absolute installation path of a package
     *
     * @throws \InvalidArgumenException if a package is not in the repository
     */
    public function setInstallPath(PackageInterface $package, $path);
}
