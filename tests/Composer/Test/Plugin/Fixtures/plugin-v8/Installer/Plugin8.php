<?php

namespace Installer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\Capable;

class Plugin8 implements PluginInterface, Capable
{
    public $version = 'installer-v8';

    public function activate(Composer $composer, IOInterface $io)
    {
        $io->write('activate v8');
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
        $io->write('deactivate v8');
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
        $io->write('uninstall v8');
    }

    public function getCapabilities()
    {
        return array(
            'Composer\Plugin\Capability\CommandProvider' => 'Installer\CommandProvider',
        );
    }
}
