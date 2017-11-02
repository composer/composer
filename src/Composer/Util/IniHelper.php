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
 * Provides ini file location functions that work with and without a restart.
 * When the process has restarted it uses a tmp ini and stores the original
 * ini locations in an environment variable.
 *
 * @author John Stevenson <john-stevenson@blueyonder.co.uk>
 */
class IniHelper
{
    const ENV_ORIGINAL = 'COMPOSER_ORIGINAL_INIS';

    /**
     * Returns an array of php.ini locations with at least one entry
     *
     * The equivalent of calling php_ini_loaded_file then php_ini_scanned_files.
     * The loaded ini location is the first entry and may be empty.
     *
     * @return array
     */
    public static function getAll()
    {
        $env = getenv(self::ENV_ORIGINAL);

        if (false !== $env) {
            return explode(PATH_SEPARATOR, $env);
        }

        $paths = array(strval(php_ini_loaded_file()));

        if ($scanned = php_ini_scanned_files()) {
            $paths = array_merge($paths, array_map('trim', explode(',', $scanned)));
        }

        return $paths;
    }

    /**
     * Describes the location of the loaded php.ini file(s)
     *
     * @return string
     */
    public static function getMessage()
    {
        $paths = self::getAll();

        if (empty($paths[0])) {
            array_shift($paths);
        }

        $ini = array_shift($paths);

        if (empty($ini)) {
            return 'A php.ini file does not exist. You will have to create one.';
        }

        if (!empty($paths)) {
            return 'Your command-line PHP is using multiple ini files. Run `php --ini` to show them.';
        }

        return 'The php.ini used by your command-line PHP is: '.$ini;
    }
}
