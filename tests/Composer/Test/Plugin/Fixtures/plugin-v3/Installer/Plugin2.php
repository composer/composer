<?php

namespace Installer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

class Plugin2 implements PluginInterface
{
    public $version = 'installer-v3';

    public function activate(Composer $composer, IOInterface $io)
    {
        $io->write('activate v3');
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
        $io->write('deactivate v3');
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
        $io->write('uninstall v3');
    }
}
