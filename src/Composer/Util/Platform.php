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
     * Parses magic constructs like tildes in paths. Right now only tildes are supported but we could add support for 
     * environment variables on various platforms.
     *
     * @param string $path
     * @return string
     */
    public static function expandPath($path)
    {
        // Tilde expansion for *nix
        if (!self::isWindows() && 0 === strpos($path, '~/')) {
            if (function_exists('posix_getuid') && function_exists('posix_getpwuid')) {
                $info = posix_getpwuid(posix_getuid());
                $home = $info['dir'];
            } else {
                $home = getenv('HOME');
            }
            // Cannot be empty or FALSE
            if (!$home) {
                throw new \RuntimeException(sprintf('No home folder found to expand ~ with in %s', $path));
            }
            $path = $home . substr($path, 1);
        }
        return $path;
    }

    /**
     * @return bool Whether the host machine is running a Windows OS
     */
    public static function isWindows()
    {
        return defined('PHP_WINDOWS_VERSION_BUILD');
    }
 }
