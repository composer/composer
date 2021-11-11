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

namespace Composer\Config;

/**
 * Configuration Source Interface
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Beau Simensen <beau@dflydev.com>
 */
interface ConfigSourceInterface
{
    /**
     * Add a repository
     *
     * @param string        $name   Name
     * @param mixed[]|false $config Configuration
     * @param bool          $append Whether the repo should be appended (true) or prepended (false)
     *
     * @return void
     */
    public function addRepository($name, $config, $append = true);

    /**
     * Remove a repository
     *
     * @param string $name
     *
     * @return void
     */
    public function removeRepository($name);

    /**
     * Add a config setting
     *
     * @param string $name  Name
     * @param mixed  $value Value
     *
     * @return void
     */
    public function addConfigSetting($name, $value);

    /**
     * Remove a config setting
     *
     * @param string $name
     *
     * @return void
     */
    public function removeConfigSetting($name);

    /**
     * Add a property
     *
     * @param string $name  Name
     * @param string $value Value
     *
     * @return void
     */
    public function addProperty($name, $value);

    /**
     * Remove a property
     *
     * @param string $name
     *
     * @return void
     */
    public function removeProperty($name);

    /**
     * Add a package link
     *
     * @param string $type  Type (require, require-dev, provide, suggest, replace, conflict)
     * @param string $name  Name
     * @param string $value Value
     *
     * @return void
     */
    public function addLink($type, $name, $value);

    /**
     * Remove a package link
     *
     * @param string $type Type (require, require-dev, provide, suggest, replace, conflict)
     * @param string $name Name
     *
     * @return void
     */
    public function removeLink($type, $name);

    /**
     * Gives a user-friendly name to this source (file path or so)
     *
     * @return string
     */
    public function getName();
}
