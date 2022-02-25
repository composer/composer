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
    public function addRepository(string $name, $config, bool $append = true): void;

    /**
     * Remove a repository
     *
     * @param string $name
     *
     * @return void
     */
    public function removeRepository(string $name): void;

    /**
     * Add a config setting
     *
     * @param string $name  Name
     * @param mixed  $value Value
     *
     * @return void
     */
    public function addConfigSetting(string $name, $value): void;

    /**
     * Remove a config setting
     *
     * @param string $name
     *
     * @return void
     */
    public function removeConfigSetting(string $name): void;

    /**
     * Add a property
     *
     * @param string $name  Name
     * @param string|string[] $value Value
     *
     * @return void
     */
    public function addProperty(string $name, $value): void;

    /**
     * Remove a property
     *
     * @param string $name
     *
     * @return void
     */
    public function removeProperty(string $name): void;

    /**
     * Add a package link
     *
     * @param string $type  Type (require, require-dev, provide, suggest, replace, conflict)
     * @param string $name  Name
     * @param string $value Value
     *
     * @return void
     */
    public function addLink(string $type, string $name, string $value): void;

    /**
     * Remove a package link
     *
     * @param string $type Type (require, require-dev, provide, suggest, replace, conflict)
     * @param string $name Name
     *
     * @return void
     */
    public function removeLink(string $type, string $name): void;

    /**
     * Gives a user-friendly name to this source (file path or so)
     *
     * @return string
     */
    public function getName(): string;
}
