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
     * Version number of the internal composer-plugin-api package
     *
     * This is used to denote the API version of Plugin specific
     * features, but is also bumped to a new major if Composer
     * includes a major break in internal APIs which are susceptible
     * to be used by plugins.
     *
     * @var string
     */
    const PLUGIN_API_VERSION = '2.1.0';

    /**
     * Apply plugin modifications to Composer
     *
     * @param Composer    $composer
     * @param IOInterface $io
     *
     * @return void
     */
    public function activate(Composer $composer, IOInterface $io);

    /**
     * Remove any hooks from Composer
     *
     * This will be called when a plugin is deactivated before being
     * uninstalled, but also before it gets upgraded to a new version
     * so the old one can be deactivated and the new one activated.
     *
     * @param Composer    $composer
     * @param IOInterface $io
     *
     * @return void
     */
    public function deactivate(Composer $composer, IOInterface $io);

    /**
     * Prepare the plugin to be uninstalled
     *
     * This will be called after deactivate.
     *
     * @param Composer    $composer
     * @param IOInterface $io
     *
     * @return void
     */
    public function uninstall(Composer $composer, IOInterface $io);
}
