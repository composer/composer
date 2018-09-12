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
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
interface AsyncRepositoryInterface
{
    /**
     * @param array $names Names of packages to retrieve data for
     * @return scalar Id to be passed to later loadPackages call
     */
    public function requestPackages(array $names);

    /**
     * @param array $names
     * @return scalar id for load call
     */
    public function returnPackages($loadId);
}

