<?php

use Composer\Json\JsonFile;
use Composer\InstalledVersions;
use Composer\Script\Event;

class Hooks
{
    public static function preUpdate(Event $event)
    {
        flush();
        echo '!!PreUpdate:'.JsonFile::encode(InstalledVersions::getInstalledPackages(), 320)."\n";
        echo '!!Versions:console:'.InstalledVersions::getVersion('symfony/console').';process:'.InstalledVersions::getVersion('symfony/process').';filesystem:'.InstalledVersions::getVersion('symfony/filesystem')."\n";
    }

    public static function postUpdate(Event $event)
    {
        echo '!!PostUpdate:'.JsonFile::encode(InstalledVersions::getInstalledPackages(), 320)."\n";
        echo '!!Versions:console:'.InstalledVersions::getVersion('symfony/console').';process:'.InstalledVersions::getVersion('symfony/process').';filesystem:'.InstalledVersions::getVersion('symfony/filesystem')."\n";
        echo '!!PluginA:'.InstalledVersions::getVersion('plugin/a')."\n";
        echo '!!PluginB:'.InstalledVersions::getVersion('plugin/b')."\n";
    }
}
