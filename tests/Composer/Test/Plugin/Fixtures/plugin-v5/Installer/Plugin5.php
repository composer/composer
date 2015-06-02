<?php

namespace Installer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

class Plugin5 implements PluginInterface
{
    public function activate(Composer $composer, IOInterface $io)
    {
    }
}
