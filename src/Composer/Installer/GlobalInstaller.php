<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Installer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Json\JsonFile;

/**
 * Global Installer installs packages in global directory.
 *
 * @author Martin Hasoň <martin.hason@gmail.com>
 */
class GlobalInstaller extends LibraryInstaller
{
    protected $globalDir;

    public function __construct(IOInterface $io, Composer $composer, $globalDir, $type = null)
    {
        $this->globalDir = $globalDir;

        parent::__construct($io, $composer, $type);
    }

    /**
     * {@inheritDoc}
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $this->initializeGlobalDir();
        parent::install($repo, $package);
    }

    /**
     * {@inheritDoc}
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        $this->initializeGlobalDir();
        parent::update($repo, $initial, $target);
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        if (!$repo->hasPackage($package)) {
            return;
        }

        $this->removeBinaries($package);
        $repo->removePackage($package);
    }

    protected function installCode(PackageInterface $package)
    {
        if (!is_readable($this->getInstallPath($package))) {
            parent::installCode($package);
        }
    }

    protected function updateCode(PackageInterface $initial, PackageInterface $target)
    {
        $this->installCode($target);
    }

    protected function removeCode(PackageInterface $package)
    {
    }

    protected function getPackageBasePath(PackageInterface $package)
    {
        $this->initializeGlobalDir();
        $this->initializeVendorDir();

        return $this->globalDir.'/'.$this->getPackagePath($package);
    }

    protected function getPackagePath(PackageInterface $package)
    {
        $version = $package->getVersion();
        if ($package->isDev() && $reference = $package->getSourceReference()) {
            $version .= '-'.(strlen($reference) === 40 ? substr($reference, 0, 7) : $reference);
        }

        return $package->getPrettyName().'-'.$version;
    }

    protected function initializeGlobalDir()
    {
        $this->filesystem->ensureDirectoryExists($this->globalDir);
        $this->globalDir = realpath($this->globalDir);
    }
}
