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
     * @param bool $isFips
     * @return string|null
     */
    public static function parseOpenssl($opensslVersion, &$isFips)
    {
        $isFips = false;

        if (!preg_match('/^(?<version>[0-9.]+)(?<patch>[a-z]{0,2})?(?<suffix>(?:-?(?:dev|pre|alpha|beta|rc|fips)[\d]*)*)?$/', $opensslVersion, $matches)) {
            return null;
        }

        // "" => 0, "a" => 1, "zg" => 33
        $patch = strlen($matches['patch']) * (-ord('a')+1)
            + array_sum(array_map('ord', str_split($matches['patch'])));

        $isFips = strpos($matches['suffix'], 'fips') !== false;
        $suffix = strtr('-'.ltrim($matches['suffix'], '-'), array('-fips' => '', '-pre' => '-alpha'));

        return rtrim($matches['version'].'.'.$patch.$suffix, '-');
    }

    /**
     * @param string $libjpegVersion
     * @return string|null
     */
    public static function parseLibjpeg($libjpegVersion)
    {
        if (!preg_match('/^(?<major>\d+)(?<minor>[a-z]*)$/', $libjpegVersion, $matches)) {
            return null;
        }

        $minor = strlen($matches['minor']) * (-ord('a')+1) + array_sum(array_map('ord', str_split($matches['minor'])));
        return $matches['major'].'.'.$minor;
    }
}
