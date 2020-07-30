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
 * @author Lars Strojny <lars@strojny.net>
 */
class Version
{
    /**
     * @param string $opensslVersion
     * @return string
     */
    public static function normalizeOpenssl($opensslVersion)
    {
        if (!preg_match('/^(?P<version>.+?)(?P<letters>[a-z]+)(?P<suffix>-[0-9a-z]+|)$/', $opensslVersion, $matches)) {
            return $opensslVersion;
        }

        $version = $matches['version'];
        $letters = $matches['letters'];
        $lettersLength = strlen($letters);
        // 0.9.8zg => 0.9.8.25.6
        // 0.9.8a => 0.9.8.0
        for ($a = 0; $a < $lettersLength; $a++) {
            $version .= '.'.(ord($letters[$a]) - 97);
        }

        return $version . $matches['suffix'];
    }
}
