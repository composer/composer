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
     * @param string $name   Name
     * @param array  $config Configuration
     */
    public function addRepository($name, $config);

    /**
     * Remove a repository
     *
     * @param string $name
     */
    public function removeRepository($name);

    /**
     * Add a config setting
     *
     * @param string $name  Name
     * @param string $value Value
     */
    public function addConfigSetting($name, $value);

    /**
     * Remove a config setting
     *
     * @param string $name
     */
    public function removeConfigSetting($name);

    /**
     * Add a package link
     *
     * @param string $type  Type (require, require-dev, provide, suggest, replace, conflict)
     * @param string $name  Name
     * @param string $value Value
     */
    public function addLink($type, $name, $value);

    /**
     * Remove a package link
     *
     * @param string $type Type (require, require-dev, provide, suggest, replace, conflict)
     * @param string $name Name
     */
    public function removeLink($type, $name);

    /**
     * Gives a user-friendly name to this source (file path or so)
     *
     * @return string
     */
    public function getName();
}
