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
use Composer\Downloader\DownloadManager;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;

/**
 * Package installation manager.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class LibraryInstaller implements InstallerInterface
{
    protected $composer;
    protected $vendorDir;
    protected $binDir;
    protected $downloadManager;
    protected $io;
    protected $type;
    protected $filesystem;

    /**
     * Initializes library installer.
     *
     * @param IOInterface $io
     * @param Composer    $composer
     */
    public function __construct(IOInterface $io, Composer $composer, $type = 'library')
    {
        $this->composer = $composer;
        $this->downloadManager = $composer->getDownloadManager();
        $this->io = $io;
        $this->type = $type;

        $this->filesystem = new Filesystem();
        $this->vendorDir = rtrim($composer->getConfig()->get('vendor-dir'), '/');
        $this->binDir = rtrim($composer->getConfig()->get('bin-dir'), '/');
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
    public function isInstalled(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        return $repo->hasPackage($package) && is_readable($this->getInstallPath($package));
    }

    /**
     * {@inheritDoc}
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $this->initializeVendorDir();
        $downloadPath = $this->getInstallPath($package);

        // remove the binaries if it appears the package files are missing
        if (!is_readable($downloadPath) && $repo->hasPackage($package)) {
            $this->removeBinaries($package);
        }

        $this->installCode($package);
        $this->installBinaries($package);
        if (!$repo->hasPackage($package)) {
            $repo->addPackage(clone $package);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        if (!$repo->hasPackage($initial)) {
            throw new \InvalidArgumentException('Package is not installed: '.$initial);
        }

        $this->initializeVendorDir();

        $this->removeBinaries($initial);
        $this->updateCode($initial, $target);
        $this->installBinaries($target);
        $repo->removePackage($initial);
        if (!$repo->hasPackage($target)) {
            $repo->addPackage(clone $target);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        if (!$repo->hasPackage($package)) {
            // TODO throw exception again here, when update is fixed and we don't have to remove+install (see #125)
            return;
            throw new \InvalidArgumentException('Package is not installed: '.$package);
        }

        $downloadPath = $this->getInstallPath($package);

        $this->removeCode($package);
        $this->removeBinaries($package);
        $repo->removePackage($package);

        if (strpos($package->getName(), '/')) {
            $packageVendorDir = dirname($downloadPath);
            if (is_dir($packageVendorDir) && !glob($packageVendorDir.'/*')) {
                @rmdir($packageVendorDir);
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getInstallPath(PackageInterface $package)
    {
        $this->initializeVendorDir();
        $targetDir = $package->getTargetDir();

        return ($this->vendorDir ? $this->vendorDir.'/' : '') . $package->getPrettyName() . ($targetDir ? '/'.$targetDir : '');
    }

    protected function installCode(PackageInterface $package)
    {
        $downloadPath = $this->getInstallPath($package);
        $this->downloadManager->download($package, $downloadPath);
    }

    protected function updateCode(PackageInterface $initial, PackageInterface $target)
    {
        $downloadPath = $this->getInstallPath($initial);
        $this->downloadManager->update($initial, $target, $downloadPath);
    }

    protected function removeCode(PackageInterface $package)
    {
        $downloadPath = $this->getInstallPath($package);
        $this->downloadManager->remove($package, $downloadPath);
    }

    protected function getBinaries(PackageInterface $package)
    {
        return $package->getBinaries();
    }

    protected function installBinaries(PackageInterface $package)
    {
        $binaries = $this->getBinaries($package);
        if (!$binaries) {
            return;
        }
        foreach ($binaries as $bin) {
            $this->initializeBinDir();
            $link = $this->binDir.'/'.basename($bin);
            if (file_exists($link)) {
                if (is_link($link)) {
                    // likely leftover from a previous install, make sure
                    // that the target is still executable in case this
                    // is a fresh install of the vendor.
                    chmod($link, 0777 & ~umask());
                }
                $this->io->write('Skipped installation of '.$bin.' for package '.$package->getName().', name conflicts with an existing file');
                continue;
            }
            $bin = $this->getInstallPath($package).'/'.$bin;

            if (defined('PHP_WINDOWS_VERSION_BUILD')) {
                // add unixy support for cygwin and similar environments
                if ('.bat' !== substr($bin, -4)) {
                    file_put_contents($link, $this->generateUnixyProxyCode($bin, $link));
                    chmod($link, 0777 & ~umask());
                    $link .= '.bat';
                }
                file_put_contents($link, $this->generateWindowsProxyCode($bin, $link));
            } else {
                $cwd = getcwd();
                try {
                    // under linux symlinks are not always supported for example
                    // when using it in smbfs mounted folder
                    $relativeBin = $this->filesystem->findShortestPath($link, $bin);
                    chdir(dirname($link));
                    symlink($relativeBin, $link);
                } catch (\ErrorException $e) {
                    file_put_contents($link, $this->generateUnixyProxyCode($bin, $link));
                }
                chdir($cwd);
            }
            chmod($link, 0777 & ~umask());
        }
    }

    protected function removeBinaries(PackageInterface $package)
    {
        $binaries = $this->getBinaries($package);
        if (!$binaries) {
            return;
        }
        foreach ($binaries as $bin) {
            $link = $this->binDir.'/'.basename($bin);
            if (!file_exists($link)) {
                continue;
            }
            unlink($link);
        }
    }

    protected function initializeVendorDir()
    {
        $this->filesystem->ensureDirectoryExists($this->vendorDir);
        $this->vendorDir = realpath($this->vendorDir);
    }

    protected function initializeBinDir()
    {
        $this->filesystem->ensureDirectoryExists($this->binDir);
        $this->binDir = realpath($this->binDir);
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
                $caller = trim($match[1]);
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
            $caller." \"%BIN_TARGET%\" %*\r\n";
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
