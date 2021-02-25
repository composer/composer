<?php

use Composer\Plugin\PluginInterface;
use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\InstalledVersions;

class PluginB implements PluginInterface
{
    public function activate(Composer $composer, IOInterface $io)
    {
        echo '!!PluginB:'.InstalledVersions::getVersion('plugin/b').JsonFile::encode(InstalledVersions::getInstalledPackages(), 320)."\n";
        echo '!!PluginA:'.(InstalledVersions::isInstalled('plugin/a') ? InstalledVersions::getVersion('plugin/a') : 'null')."\n";
        echo '!!Versions:console:'.InstalledVersions::getVersion('symfony/console').';process:'.InstalledVersions::getVersion('symfony/process').';filesystem:'.InstalledVersions::getVersion('symfony/filesystem')."\n";
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
    }
}
