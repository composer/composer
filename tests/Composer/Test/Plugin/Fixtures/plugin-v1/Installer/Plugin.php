<?php

namespace Installer;

use Composer\Composer;
use Composer\Plugin\PluginInterface;

class Plugin implements PluginInterface
{
    public $version = 'installer-v1';

    public function activate()
    {
    }
}
