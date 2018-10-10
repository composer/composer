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

interface VersionCacheInterface
{
    /**
     * @param string $version
     * @param string $identifier
     * @return array Package version data
     */
    public function getVersionPackage($version, $identifier);
}
