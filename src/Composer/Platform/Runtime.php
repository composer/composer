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

/**
 * An abstraction of the concrete runtime
 */
interface Runtime
{
    /**
     * @param string $constant
     * @param class-string $class
     * @return bool
     */
    public function hasConstant($constant, $class = null);

    /**
     * @param bool $constant
     * @param class-string $class
     * @return mixed
     */
    public function getConstant($constant, $class = null);

    /**
     * @param callable $callable
     * @param array $arguments
     * @return mixed
     */
    public function invoke($callable, array $arguments = array());

    /**
     * @param class-string $class
     * @return bool
     */
    public function hasClass($class);

    /**
     * @param class-string $class
     * @param array $arguments
     * @return object
     */
    public function construct($class, array $arguments = array());

    /** @return string[] */
    public function getExtensions();

    /**
     * @param string $extension
     * @return string
     */
    public function getExtensionVersion($extension);

    /**
     * @param string $extension
     * @return string
     */
    public function getExtensionInfo($extension);
}
