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

use Composer\Composer;
use Composer\IO\IOInterface;

/**
 * Plugin interface
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
interface PluginInterface
{
    /**
     * Version number of the fake composer-plugin-api package
     *
     * @var string
     */
    const PLUGIN_API_VERSION = '1.0.0';

    /**
     * Apply plugin modifications to composer
     *
     * @param Composer    $composer
     * @param IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io);
}
