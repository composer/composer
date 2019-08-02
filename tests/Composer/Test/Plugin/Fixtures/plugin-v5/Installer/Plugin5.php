<?php

namespace Installer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

class Plugin5 implements PluginInterface
{
    public function activate(Composer $composer, IOInterface $io)
    {
        $io->write('activate v5');
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
        $io->write('deactivate v5');
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
        $io->write('uninstall v5');
    }
}
