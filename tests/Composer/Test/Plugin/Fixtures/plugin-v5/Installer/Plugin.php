<?php

namespace Installer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\CommandsProviderInterface;

class Plugin implements PluginInterface, CommandsProviderInterface
{
    public $version = 'installer-v5';

    public function activate(Composer $composer, IOInterface $io)
    {
    }

    public function getCommands()
    {
        return null;
    }
}
