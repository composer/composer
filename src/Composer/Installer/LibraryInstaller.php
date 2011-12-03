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

use Composer\Downloader\DownloadManager;
use Composer\Repository\WritableRepositoryInterface;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\Package\PackageInterface;
use Composer\Downloader\Util\Filesystem;

/**
 * Package installation manager.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class LibraryInstaller implements InstallerInterface
{
    protected $vendorDir;
    protected $binDir;
    protected $downloadManager;
    protected $repository;
    private $type;

    /**
     * Initializes library installer.
     *
     * @param   string                      $vendorDir  relative path for packages home
     * @param   string                      $binDir     relative path for binaries
     * @param   DownloadManager             $dm         download manager
     * @param   WritableRepositoryInterface $repository repository controller
     * @param   string                      $type       package type that this installer handles
     */
    public function __construct($vendorDir, $binDir, DownloadManager $dm, WritableRepositoryInterface $repository, $type = 'library')
    {
        $this->downloadManager = $dm;
        $this->repository = $repository;
        $this->type = $type;

        $fs = new Filesystem();
        $fs->ensureDirectoryExists($vendorDir);
        $fs->ensureDirectoryExists($binDir);
        $this->vendorDir = realpath($vendorDir);
        $this->binDir = realpath($binDir);
    }

    /**
     * {@inheritDoc}
     */
    public function supports($packageType)
    {
        return $packageType === $this->type || null === $this->type;
    }

    /**
     * {@inheritDoc}
     */
    public function isInstalled(PackageInterface $package)
    {
        return $this->repository->hasPackage($package);
    }

    /**
     * {@inheritDoc}
     */
    public function install(PackageInterface $package)
    {
        $downloadPath = $this->getInstallPath($package);

        $this->downloadManager->download($package, $downloadPath);
        $this->installBinaries($package);
        $this->repository->addPackage(clone $package);
    }

    /**
     * {@inheritDoc}
     */
    public function update(PackageInterface $initial, PackageInterface $target)
    {
        if (!$this->repository->hasPackage($initial)) {
            throw new \InvalidArgumentException('Package is not installed: '.$initial);
        }

        $downloadPath = $this->getInstallPath($initial);

        $this->removeBinaries($initial);
        $this->downloadManager->update($initial, $target, $downloadPath);
        $this->installBinaries($target);
        $this->repository->removePackage($initial);
        $this->repository->addPackage(clone $target);
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall(PackageInterface $package)
    {
        if (!$this->repository->hasPackage($package)) {
            // TODO throw exception again here, when update is fixed and we don't have to remove+install (see #125)
            return;
            throw new \InvalidArgumentException('Package is not installed: '.$package);
        }

        $downloadPath = $this->getInstallPath($package);

        $this->downloadManager->remove($package, $downloadPath);
        $this->removeBinaries($package);
        $this->repository->removePackage($package);
    }

    /**
     * {@inheritDoc}
     */
    public function getInstallPath(PackageInterface $package)
    {
        $targetDir = $package->getTargetDir();
        return ($this->vendorDir ? $this->vendorDir.'/' : '') . $package->getName() . ($targetDir ? '/'.$targetDir : '');
    }

    protected function installBinaries(PackageInterface $package)
    {
        if (!$package->getBinaries()) {
            return;
        }
        foreach ($package->getBinaries() as $bin => $os) {
            $link = $this->binDir.'/'.basename($bin);
            if (file_exists($link)) {
                continue;
            }

            // skip windows
            if (defined('PHP_WINDOWS_VERSION_BUILD') && false === strpos($os, 'windows') && '*' !== $os) {
                continue;
            }

            // skip unix
            if (!defined('PHP_WINDOWS_VERSION_BUILD') && false === strpos($os, 'unix') && '*' !== $os) {
                continue;
            }

            $binary = $this->getInstallPath($package).'/'.$bin;
            $from = array(
                '@php_bin@',
                '@bin_dir@',
            );
            $to = array(
                'php',
                $this->binDir,
            );
            file_put_contents($binary, str_replace($from, $to, file_get_contents($binary)));

            if (defined('PHP_WINDOWS_VERSION_BUILD')) {
                copy($binary, $link);
            } else {
                symlink($this->getInstallPath($package).'/'.$bin, $link);
            }
            chmod($link, 0777);
        }
    }

    protected function removeBinaries(PackageInterface $package)
    {
        if (!$package->getBinaries()) {
            return;
        }
        foreach ($package->getBinaries() as $bin => $os) {
            $link = $this->binDir.'/'.basename($bin);
            if (!file_exists($link)) {
                continue;
            }
            unlink($link);
        }
    }
}
