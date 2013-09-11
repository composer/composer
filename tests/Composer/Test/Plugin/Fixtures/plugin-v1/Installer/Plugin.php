<?php

namespace Installer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

class Plugin implements PluginInterface
{
    public $version = 'installer-v1';

    public function activate(Composer $composer, IOInterface $io)
    {
    }
}
