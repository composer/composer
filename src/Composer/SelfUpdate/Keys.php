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

namespace Composer\SelfUpdate;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Keys
{
    public static function fingerprint($path)
    {
        $hash = strtoupper(hash('sha256', preg_replace('{\s}', '', file_get_contents($path))));

        return implode(' ', array(
            substr($hash, 0, 8),
            substr($hash, 8, 8),
            substr($hash, 16, 8),
            substr($hash, 24, 8),
            '', // Extra space
            substr($hash, 32, 8),
            substr($hash, 40, 8),
            substr($hash, 48, 8),
            substr($hash, 56, 8),
        ));
    }
}
