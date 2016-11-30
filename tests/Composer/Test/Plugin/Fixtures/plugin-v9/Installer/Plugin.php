<?php

declare(strict_types = 1);

namespace Installer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

class Plugin implements PluginInterface
{
    public $version = 'installer-v9';

    public function activate(Composer $composer, IOInterface $io)
    {
    }
}
