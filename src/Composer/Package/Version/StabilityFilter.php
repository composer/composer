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

namespace Composer\Package\Version;

use Composer\Package\BasePackage;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class StabilityFilter
{
    /**
     * Checks if any of the provided package names in the given stability match the configured acceptable stability and flags
     *
     * @return bool true if any package name is acceptable
     */
    public static function isPackageAcceptable(array $acceptableStabilities, array $stabilityFlags, $names, $stability)
    {
        foreach ($names as $name) {
            // allow if package matches the package-specific stability flag
            if (isset($stabilityFlags[$name])) {
                if (BasePackage::$stabilities[$stability] <= $stabilityFlags[$name]) {
                    return true;
                }
            } elseif (isset($acceptableStabilities[$stability])) {
                // allow if package matches the global stability requirement and has no exception
                return true;
            }
        }

        return false;
    }
}
