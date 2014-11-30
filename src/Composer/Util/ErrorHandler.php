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

use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Convert PHP errors into exceptions
 *
 * @author Artem Lopata <biozshock@gmail.com>
 */
class ErrorHandler
{
    /**
    * @var Output handler
    */
    private static $output = null;

    /**
     * Error handler
     *
     * @param int    $level   Level of the error raised
     * @param string $message Error message
     * @param string $file    Filename that the error was raised in
     * @param int    $line    Line number the error was raised at
     *
     * @throws \ErrorException
     */
    public static function handle($level, $message, $file, $line)
    {
        // respect error_reporting being disabled
        if (!error_reporting()) {
            return;
        }

        if (ini_get('xdebug.scream')) {
            $message .= "\n\nWarning: You have xdebug.scream enabled, the warning above may be".
            "\na legitimately suppressed error that you were not supposed to see.";
        }

        // Don't throw ErrorException for deprecation notices
        if (in_array($level, array(E_USER_DEPRECATED, E_DEPRECATED))) {
            self::outputDeprecationMessage($message);
            return;
        }

        throw new \ErrorException($message, 0, $level, $file, $line);
    }

    /**
     * Register error handler
     */
    public static function register()
    {
        set_error_handler(array(__CLASS__, 'handle'));
    }

    /**
     * Set output handler
     */
    public static function setOutput(ConsoleOutput $output)
    {
        self::$output = $output;
    }

    /**
     * Print a message to STDOUT or the output handler
     */
    private static function outputDeprecationMessage($message)
    {
        if (isset(self::$output)) {
            self::$output->writeln('<warning>PHP Deprecation: ' . $message . '</warning>');
            return;
        }

        echo 'PHP Deprecation: ', $message, PHP_EOL;
    }
}
