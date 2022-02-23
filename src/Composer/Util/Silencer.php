<?php declare(strict_types=1);

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
    public static function suppress(?int $mask = null): int
    {
        if (!isset($mask)) {
            $mask = E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED | E_STRICT;
        }
        $old = error_reporting();
        self::$stack[] = $old;
        error_reporting($old & ~$mask);

        return $old;
    }

    /**
     * Restores a single state.
     *
     * @return void
     */
    public static function restore(): void
    {
        if (!empty(self::$stack)) {
            error_reporting(array_pop(self::$stack));
        }
    }

    /**
     * Calls a specified function while silencing warnings and below.
     *
     * @param  callable   $callable Function to execute.
     * @param  mixed      $parameters Function to execute.
     * @throws \Exception Any exceptions from the callback are rethrown.
     * @return mixed      Return value of the callback.
     */
    public static function call(callable $callable, ...$parameters)
    {
        try {
            self::suppress();
            $result = $callable(...$parameters);
            self::restore();

            return $result;
        } catch (\Exception $e) {
            // Use a finally block for this when requirements are raised to PHP 5.5
            self::restore();
            throw $e;
        }
    }
}
