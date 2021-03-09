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

use Composer\IO\IOInterface;

/**
 * Convert PHP errors into exceptions
 *
 * @author Artem Lopata <biozshock@gmail.com>
 */
class ErrorHandler
{
    private static $io;

    /**
     * Error handler
     *
     * @param int    $level   Level of the error raised
     * @param string $message Error message
     * @param string $file    Filename that the error was raised in
     * @param int    $line    Line number the error was raised at
     *
     * @static
     * @throws \ErrorException
     * @return bool
     */
    public static function handle($level, $message, $file, $line)
    {
        // error code is not included in error_reporting
        if (!(error_reporting() & $level)) {
            return true;
        }

        if (filter_var(ini_get('xdebug.scream'), FILTER_VALIDATE_BOOLEAN)) {
            $message .= "\n\nWarning: You have xdebug.scream enabled, the warning above may be".
            "\na legitimately suppressed error that you were not supposed to see.";
        }

        if ($level !== E_DEPRECATED && $level !== E_USER_DEPRECATED) {
            throw new \ErrorException($message, 0, $level, $file, $line);
        }

        if (self::$io) {
            self::$io->writeError('<warning>Deprecation Notice: '.$message.' in '.$file.':'.$line.'</warning>');
            if (self::$io->isVerbose()) {
                self::$io->writeError('<warning>Stack trace:</warning>');
                self::$io->writeError(array_filter(array_map(function ($a) {
                    if (isset($a['line'], $a['file'])) {
                        return '<warning> '.$a['file'].':'.$a['line'].'</warning>';
                    }

                    return null;
                }, array_slice(debug_backtrace(), 2))));
            }
        }

        return true;
    }

    /**
     * Register error handler.
     *
     * @param IOInterface|null $io
     */
    public static function register(IOInterface $io = null)
    {
        set_error_handler(array(__CLASS__, 'handle'));
        error_reporting(E_ALL | E_STRICT);
        self::$io = $io;
    }
}
