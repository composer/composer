<?php

use Composer\Json\JsonFile;
use Composer\InstalledVersions;
use Composer\Script\Event;

class Hooks
{
    public static function preUpdate(Event $event)
    {
        fwrite(STDERR, '!!PreUpdate:'.JsonFile::encode(InstalledVersions::getInstalledPackages(), 320)."\n");
        fwrite(STDERR, '!!Versions:console:'.InstalledVersions::getVersion('symfony/console').';process:'.InstalledVersions::getVersion('symfony/process').';filesystem:'.InstalledVersions::getVersion('symfony/filesystem')."\n");
    }

    public static function postUpdate(Event $event)
    {
        fwrite(STDERR, '!!PostUpdate:'.JsonFile::encode(InstalledVersions::getInstalledPackages(), 320)."\n");
        fwrite(STDERR, '!!Versions:console:'.InstalledVersions::getVersion('symfony/console').';process:'.InstalledVersions::getVersion('symfony/process').';filesystem:'.InstalledVersions::getVersion('symfony/filesystem')."\n");
    }
}
