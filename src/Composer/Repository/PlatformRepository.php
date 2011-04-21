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

use Composer\Package\MemoryPackage;
use Composer\Package\BasePackage;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PlatformRepository extends ArrayRepository
{
    protected $packages;

    protected function initialize()
    {
        parent::initialize();

        $version = BasePackage::parseVersion(PHP_VERSION);

        // TODO mark as type platform and create a special installer that skips it + one that throws an exception
        $php = new MemoryPackage('php', $version['version'], $version['type']);
        $this->addPackage($php);

        // TODO check for php extensions
    }
}