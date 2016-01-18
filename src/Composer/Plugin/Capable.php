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

namespace Composer\Plugin;

/**
 * Plugins which need to expose various implementations
 * of the Composer Plugin Capabilities must have their
 * declared Plugin class implementing this interface.
 *
 * @api
 */
interface Capable
{
    /**
     * Method by which a Plugin announces its API implementations, through an array
     * with a special structure.
     *
     * The key must be a string, representing a fully qualified class/interface name
     * which Composer Plugin API exposes - named "API class".
     * The value must be a string as well, representing the fully qualified class name
     * of the API class - named "SPI class".
     *
     * Every SPI must implement their API class.
     *
     * Every SPI will be passed a single array parameter via their constructor.
     *
     * Example:
     * // API as key, SPI as value
     * return array(
     *      'Composer\Plugin\Capability\CommandProvider' => 'My\CommandProvider',
     *      'Composer\Plugin\Capability\Validator'       => 'My\Validator',
     * );
     *
     * @return string[]
     */
    public function getCapabilities();
}
