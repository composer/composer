<?php

namespace Installer;

use Composer\Installer\InstallerInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\WritableRepositoryInterface;

class Custom2 implements InstallerInterface
{
    public $version = 'installer-v3';

    public function supports($packageType) {}
    public function isInstalled(WritableRepositoryInterface $repo, PackageInterface $package) {}
    public function install(WritableRepositoryInterface $repo, PackageInterface $package) {}
    public function update(WritableRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target) {}
    public function uninstall(WritableRepositoryInterface $repo, PackageInterface $package) {}
    public function getInstallPath(PackageInterface $package) {}
}
