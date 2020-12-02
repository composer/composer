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

namespace Composer\Platform;

class Runtime
{
    /**
     * @param  string       $constant
     * @param  class-string $class
     * @return bool
     */
    public function hasConstant($constant, $class = null)
    {
        return defined(ltrim($class.'::'.$constant, ':'));
    }

    /**
     * @param  bool         $constant
     * @param  class-string $class
     * @return mixed
     */
    public function getConstant($constant, $class = null)
    {
        return constant(ltrim($class.'::'.$constant, ':'));
    }

    /**
     * @param  string $fn
     * @return bool
     */
    public function hasFunction($fn)
    {
        return function_exists($fn);
    }

    /**
     * @param  callable $callable
     * @param  array    $arguments
     * @return mixed
     */
    public function invoke($callable, array $arguments = array())
    {
        return call_user_func_array($callable, $arguments);
    }

    /**
     * @param  class-string $class
     * @return bool
     */
    public function hasClass($class)
    {
        return class_exists($class, false);
    }

    /**
     * @param  class-string $class
     * @param  array        $arguments
     * @return object
     */
    public function construct($class, array $arguments = array())
    {
        if (empty($arguments)) {
            return new $class;
        }

        $refl = new \ReflectionClass($class);

        return $refl->newInstanceArgs($arguments);
    }

    /** @return string[] */
    public function getExtensions()
    {
        return get_loaded_extensions();
    }

    /**
     * @param  string $extension
     * @return string
     */
    public function getExtensionVersion($extension)
    {
        return phpversion($extension);
    }

    /**
     * @param  string $extension
     * @return string
     */
    public function getExtensionInfo($extension)
    {
        $reflector = new \ReflectionExtension($extension);

        ob_start();
        $reflector->info();

        return ob_get_clean();
    }
}
