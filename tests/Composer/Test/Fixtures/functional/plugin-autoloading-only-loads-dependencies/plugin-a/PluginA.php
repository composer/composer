<?php

use Composer\Plugin\PluginInterface;
use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\InstalledVersions;

class PluginA implements PluginInterface
{
    public function activate(Composer $composer, IOInterface $io)
    {
        fwrite(STDERR, "!!PluginAInit\n");
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
    }
}
