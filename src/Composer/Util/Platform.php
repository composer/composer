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
        if (preg_match('#^~[\\/]#', $path)) {
            return self::getUserDirectory() . substr($path, 1);
        }
        return preg_replace_callback('#^(\$|(?P<percent>%))(?P<var>\w++)(?(percent)%)(?P<path>.*)#', function($matches) {
            // Treat HOME as an alias for USERPROFILE on Windows for legacy reasons
            if (Platform::isWindows() && $matches['var'] == 'HOME') {
                return (getenv('HOME') ?: getenv('USERPROFILE')) . $matches['path'];
            }
            return getenv($matches['var']) . $matches['path'];
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
        }

        if (self::isWindows() && false !== ($home = getenv('USERPROFILE'))) {
            return $home;
        }

        if (function_exists('posix_getuid') && function_exists('posix_getpwuid')) {
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

    /**
     * @param  string $str
     * @return int return a guaranteed binary length of the string, regardless of silly mbstring configs
     */
    public static function strlen($str)
    {
        static $useMbString = null;
        if (null === $useMbString) {
            $useMbString = function_exists('mb_strlen') && ini_get('mbstring.func_overload');
        }

        if ($useMbString) {
            return mb_strlen($str, '8bit');
        }

        return strlen($str);
    }
}
