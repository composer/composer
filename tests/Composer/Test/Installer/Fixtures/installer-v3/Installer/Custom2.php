<?php

namespace Installer;

use Composer\Installer\InstallerInterface;
use Composer\Package\PackageInterface;

class Custom2 implements InstallerInterface
{
    public $version = 'installer-v3';

    public function supports($packageType) {}
    public function isInstalled(PackageInterface $package) {}
    public function install(PackageInterface $package) {}
    public function update(PackageInterface $initial, PackageInterface $target) {}
    public function uninstall(PackageInterface $package) {}
    public function getInstallPath(PackageInterface $package) {}
}
