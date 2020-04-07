<?php

namespace Installer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

class Plugin6 implements PluginInterface
{
    public function activate(Composer $composer, IOInterface $io)
    {
        $io->write('activate v6');
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
        $io->write('deactivate v6');
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
        $io->write('uninstall v6');
    }
}
