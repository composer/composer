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
     * Parses tildes and environment variables in paths.
     *
     * @param string $path
     * @return string
     */
    public static function expandPath($path)
    {
        if (preg_match('#^~[/\\\\]#', $path)) {
            return self::getUserDirectory() . substr($path, 1);
        }
        return preg_replace_callback('#^([\\$%])(\\w+)\\1?(([/\\\\].*)?)#', function($matches) {
            // Treat HOME as an alias for USERPROFILE on Windows for legacy reasons
            if (Platform::isWindows() && $matches[2] == 'HOME') {
                return (getenv('HOME') ?: getenv('USERPROFILE')) . $matches[3];
            }
            return getenv($matches[2]) . $matches[3];
        }, $path);
    }

    /**
     * @return string The formal user home as detected from environment parameters
     * @throws \RuntimeException If the user home could not reliably be determined
     */
    public static function getUserDirectory()
    {
        if (false !== ($home = getenv('HOME'))) {
            return $home;
        } elseif (self::isWindows() && false !== ($home = getenv('USERPROFILE'))) {
            return $home;
        } elseif (function_exists('posix_getuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_getuid());
            return $info['dir'];
        }
        throw new \RuntimeException('Could not determine user directory');
    }

    /**
     * @return bool Whether the host machine is running a Windows OS
     */
    public static function isWindows()
    {
        return defined('PHP_WINDOWS_VERSION_BUILD');
    }
}
