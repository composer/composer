<?php

namespace Installer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

class Plugin7 implements PluginInterface
{
    public function activate(Composer $composer, IOInterface $io)
    {
        $io->write('activate v7');
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
        $io->write('deactivate v7');
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
        $io->write('uninstall v7');
    }
}
