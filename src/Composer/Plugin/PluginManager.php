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
use Composer\Package\PackageInterface;

/**
 * Plugin manager
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class PluginManager
{
    protected $composer;

    protected $plugins = array();

    /**
     * Initializes plugin manager
     *
     * @param Composer $composer
     */
    public function __construct(Composer $composer)
    {
        $this->composer = $composer;
    }

    /**
     * Adds plugin
     *
     * @param PluginInterface $plugin plugin instance
     */
    public function addPlugin(PluginInterface $plugin)
    {
        $this->plugins[] =  $plugin;
        $plugin->activate($this->composer);
    }
}
