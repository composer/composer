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

namespace Composer\Util;

/**
 * Platform helper for uniform platform-specific tests.
 *
 * @author Niels Keurentjes <niels.keurentjes@omines.com>
 */
class Platform
{
    /**
     * @return bool Whether the host machine is running a Windows OS
     */
    public static function isWindows()
    {
        return defined('PHP_WINDOWS_VERSION_BUILD');
    }
}
