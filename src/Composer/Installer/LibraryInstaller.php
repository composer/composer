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
    private $filesystem;

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

        $this->filesystem = new Filesystem();
        $this->filesystem->ensureDirectoryExists($vendorDir);
        $this->filesystem->ensureDirectoryExists($binDir);
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
        foreach ($package->getBinaries() as $bin) {
            $link = $this->binDir.'/'.basename($bin);
            if (file_exists($link)) {
                echo 'Skipped installation of '.$bin.' for package '.$package->getName().', name conflicts with an existing file';
                continue;
            }
            $bin = $this->getInstallPath($package).'/'.$bin;

            if (defined('PHP_WINDOWS_VERSION_BUILD')) {
                // add unixy support for cygwin and similar environments
                if ('.bat' !== substr($bin, -4)) {
                    file_put_contents($link, $this->generateUnixyProxyCode($bin, $link));
                    chmod($link, 0777);
                    $link .= '.bat';
                }
                file_put_contents($link, $this->generateWindowsProxyCode($bin, $link));
            } else {
                symlink($bin, $link);
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

    private function generateWindowsProxyCode($bin, $link)
    {
        $binPath = $this->filesystem->findShortestPath($link, $bin);
        if ('.bat' === substr($bin, -4)) {
            $caller = 'call';
        } else {
            $handle = fopen($bin, 'r');
            $line = fgets($handle);
            fclose($handle);
            if (preg_match('{^#!/(?:usr/bin/env )?(?:[^/]+/)*(.+)$}m', $line, $match)) {
                $caller = $match[1];
            } else {
                $caller = 'php';
            }
        }

        return "@echo off\r\n".
            "pushd .\r\n".
            "cd %~dp0\r\n".
            "cd ".escapeshellarg(dirname($binPath))."\r\n".
            "set BIN_TARGET=%CD%\\".basename($binPath)."\r\n".
            "popd\r\n".
            $caller." %BIN_TARGET% %*\r\n";
    }

    private function generateUnixyProxyCode($bin, $link)
    {
        $binPath = $this->filesystem->findShortestPath($link, $bin);

        return "#!/usr/bin/env sh\n".
            'SRC_DIR=`pwd`'."\n".
            'cd `dirname "$0"`'."\n".
            'cd '.escapeshellarg(dirname($binPath))."\n".
            'BIN_TARGET=`pwd`/'.basename($binPath)."\n".
            'cd $SRC_DIR'."\n".
            '$BIN_TARGET "$@"'."\n";
    }
}
