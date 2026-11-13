<?php

namespace Acme\InstallerPlugin;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

class Plugin implements PluginInterface
{
    public function activate(Composer $composer, IOInterface $io)
    {
        $io->write('activate custom-installer-plugin');
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
        $io->write('deactivate custom-installer-plugin');
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
        $io->write('uninstall custom-installer-plugin');
    }
}
