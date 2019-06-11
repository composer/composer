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
     * @param  string           $version
     * @param  string           $identifier
     * @return array|null|false Package version data if found, false to indicate the identifier is known but has no package, null for an unknown identifier
     */
    public function getVersionPackage($version, $identifier);
}
