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
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
interface NotifiableRepositoryInterface extends RepositoryInterface
{
    /**
     * Notify this repository about the installation of a package
     *
     * @param PackageInterface[] $packages Packages that were installed
     */
    public function notifyInstalls(array $packages);
}
