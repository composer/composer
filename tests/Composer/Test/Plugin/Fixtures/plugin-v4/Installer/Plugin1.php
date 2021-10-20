<?php

namespace Installer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

class Plugin1 implements PluginInterface
{
    public $name = 'plugin1';
    public $version = 'installer-v4';

    public function activate(Composer $composer, IOInterface $io)
    {
        $io->write('activate v4-plugin1');
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
        $io->write('deactivate v4-plugin1');
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
        $io->write('uninstall v4-plugin1');
    }
}
