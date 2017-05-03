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
 * Temporarily suppress PHP error reporting, usually warnings and below.
 *
 * @author Niels Keurentjes <niels.keurentjes@omines.com>
 */
class Silencer
{
    /**
     * @var int[] Unpop stack
     */
    private static $stack = array();

    /**
     * Suppresses given mask or errors.
     *
     * @param  int|null $mask Error levels to suppress, default value NULL indicates all warnings and below.
     * @return int      The old error reporting level.
     */
    public static function suppress($mask = null)
    {
        if (!isset($mask)) {
            $mask = E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED | E_STRICT;
        }
        $old = error_reporting();
        array_push(self::$stack, $old);
        error_reporting($old & ~$mask);

        return $old;
    }

    /**
     * Restores a single state.
     */
    public static function restore()
    {
        if (!empty(self::$stack)) {
            error_reporting(array_pop(self::$stack));
        }
    }

    /**
     * Calls a specified function while silencing warnings and below.
     *
     * Future improvement: when PHP requirements are raised add Callable type hint (5.4) and variadic parameters (5.6)
     *
     * @param  callable   $callable Function to execute.
     * @throws \Exception Any exceptions from the callback are rethrown.
     * @return mixed      Return value of the callback.
     */
    public static function call($callable /*, ...$parameters */)
    {
        try {
            self::suppress();
            $result = call_user_func_array($callable, array_slice(func_get_args(), 1));
            self::restore();

            return $result;
        } catch (\Exception $e) {
            // Use a finally block for this when requirements are raised to PHP 5.5
            self::restore();
            throw $e;
        }
    }
}
