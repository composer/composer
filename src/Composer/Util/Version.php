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
    public static function parseOpenssl($opensslVersion, &$isFips)
    {
        $isFips = false;

        if (!preg_match('/^(?P<version>[0-9\.]+)(?P<letters>[a-z]{0,2})?(?P<suffix>(?:-?(?:alpha|beta|dev|fips|pre|rc)[\d]*)*)?$/', $opensslVersion, $matches)) {
            return $opensslVersion;
        }

        $version = $matches['version'];
        $letters = $matches['letters'];
        $lettersLength = strlen($letters);
        // 0.9.8zg => 0.9.8.33
        // 0.9.8a => 0.9.8.1
        $patch = 0;
        for ($a = 0; $a < $lettersLength; $a++) {
            $patch += ord($letters[$a]) - 96;
        }

        $suffix = $matches['suffix'];
        if ($suffix !== '') {
            $suffix = '-' . ltrim($suffix, '-');
        }
        $isFips = strpos($suffix, 'fips') !== false;

        return $version.'.'.$patch.strtr($suffix, array('-fips' => '', '-pre' => '-alpha'));
    }
}
