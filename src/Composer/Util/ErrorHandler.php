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
 * Convert PHP E_NOTICE, E_WARNING into exceptions
 *
 * @author Artem Lopata <biozshock@gmail.com>
 */
class ErrorHandler
{
    /**
     * Error handler
     *
     * @param int    $errorNo     Level of the error raised
     * @param string $errorString Error message
     * @param string $errorFile   Filename that the error was raised in
     * @param int    $errorLine   Line number the error was raised at
     *
     * @static
     * @throws \ErrorException
     */
    public static function handle($errorNo, $errorString, $errorFile, $errorLine)
    {
        //this allows error suppression in 3rd party code to work
        if (!error_reporting()) {
            return;
        }

        throw new \ErrorException(sprintf('%s in %s:%d', $errorString, $errorFile, $errorLine), $errorNo);
    }

    /**
     * Set error handler
     *
     * @static
     */
    public static function set()
    {
        set_error_handler(array(__CLASS__, 'handle'));
    }
}
