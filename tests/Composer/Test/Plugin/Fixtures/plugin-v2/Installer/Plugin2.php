<?php

namespace Installer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

class Plugin2 implements PluginInterface
{
    public $version = 'installer-v2';

    public function activate(Composer $composer, IOInterface $io)
    {
    }
}
