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
use Composer\Util\Filesystem;
use Composer\Util\Silencer;
use Composer\Util\Platform;
use React\Promise\PromiseInterface;

/**
 * Package installation manager.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class LibraryInstaller implements InstallerInterface, BinaryPresenceInterface
{
    protected $composer;
    protected $vendorDir;
    protected $binDir;
    protected $downloadManager;
    protected $io;
    protected $type;
    protected $filesystem;
    protected $binCompat;
    protected $binaryInstaller;

    /**
     * Initializes library installer.
     *
     * @param IOInterface     $io
     * @param Composer        $composer
     * @param string|null     $type
     * @param Filesystem      $filesystem
     * @param BinaryInstaller $binaryInstaller
     */
    public function __construct(IOInterface $io, Composer $composer, $type = 'library', Filesystem $filesystem = null, BinaryInstaller $binaryInstaller = null)
    {
        $this->composer = $composer;
        $this->downloadManager = $composer->getDownloadManager();
        $this->io = $io;
        $this->type = $type;

        $this->filesystem = $filesystem ?: new Filesystem();
        $this->vendorDir = rtrim($composer->getConfig()->get('vendor-dir'), '/');
        $this->binaryInstaller = $binaryInstaller ?: new BinaryInstaller($this->io, rtrim($composer->getConfig()->get('bin-dir'), '/'), $composer->getConfig()->get('bin-compat'), $this->filesystem);
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
        if (!$repo->hasPackage($package)) {
            return false;
        }

        $installPath = $this->getInstallPath($package);

        if (is_readable($installPath)) {
            return true;
        }

        return (Platform::isWindows() && $this->filesystem->isJunction($installPath)) || is_link($installPath);
    }

    /**
     * {@inheritDoc}
     */
    public function download(PackageInterface $package, PackageInterface $prevPackage = null)
    {
        $this->initializeVendorDir();
        $downloadPath = $this->getInstallPath($package);

        return $this->downloadManager->download($package, $downloadPath, $prevPackage);
    }

    /**
     * {@inheritDoc}
     */
    public function prepare($type, PackageInterface $package, PackageInterface $prevPackage = null)
    {
        $this->initializeVendorDir();
        $downloadPath = $this->getInstallPath($package);

        return $this->downloadManager->prepare($type, $package, $downloadPath, $prevPackage);
    }

    /**
     * {@inheritDoc}
     */
    public function cleanup($type, PackageInterface $package, PackageInterface $prevPackage = null)
    {
        $this->initializeVendorDir();
        $downloadPath = $this->getInstallPath($package);

        return $this->downloadManager->cleanup($type, $package, $downloadPath, $prevPackage);
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
            $this->binaryInstaller->removeBinaries($package);
        }

        $promise = $this->installCode($package);
        if (!$promise instanceof PromiseInterface) {
            $promise = \React\Promise\resolve();
        }

        $binaryInstaller = $this->binaryInstaller;
        $installPath = $this->getInstallPath($package);

        return $promise->then(function () use ($binaryInstaller, $installPath, $package, $repo) {
            $binaryInstaller->installBinaries($package, $installPath);
            if (!$repo->hasPackage($package)) {
                $repo->addPackage(clone $package);
            }
        });
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

        $this->binaryInstaller->removeBinaries($initial);
        $promise = $this->updateCode($initial, $target);
        if (!$promise instanceof PromiseInterface) {
            $promise = \React\Promise\resolve();
        }

        $binaryInstaller = $this->binaryInstaller;
        $installPath = $this->getInstallPath($target);

        return $promise->then(function () use ($binaryInstaller, $installPath, $target, $initial, $repo) {
            $binaryInstaller->installBinaries($target, $installPath);
            $repo->removePackage($initial);
            if (!$repo->hasPackage($target)) {
                $repo->addPackage(clone $target);
            }
        });
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        if (!$repo->hasPackage($package)) {
            throw new \InvalidArgumentException('Package is not installed: '.$package);
        }

        $promise = $this->removeCode($package);
        if (!$promise instanceof PromiseInterface) {
            $promise = \React\Promise\resolve();
        }

        $binaryInstaller = $this->binaryInstaller;
        $downloadPath = $this->getPackageBasePath($package);
        $filesystem = $this->filesystem;

        return $promise->then(function () use ($binaryInstaller, $filesystem, $downloadPath, $package, $repo) {
            $binaryInstaller->removeBinaries($package);
            $repo->removePackage($package);

            if (strpos($package->getName(), '/')) {
                $packageVendorDir = dirname($downloadPath);
                if (is_dir($packageVendorDir) && $filesystem->isDirEmpty($packageVendorDir)) {
                    Silencer::call('rmdir', $packageVendorDir);
                }
            }
        });
    }

    /**
     * {@inheritDoc}
     */
    public function getInstallPath(PackageInterface $package)
    {
        $this->initializeVendorDir();

        $basePath = ($this->vendorDir ? $this->vendorDir.'/' : '') . $package->getPrettyName();
        $targetDir = $package->getTargetDir();

        return $basePath . ($targetDir ? '/'.$targetDir : '');
    }

    /**
     * Make sure binaries are installed for a given package.
     *
     * @param PackageInterface $package Package instance
     */
    public function ensureBinariesPresence(PackageInterface $package)
    {
        $this->binaryInstaller->installBinaries($package, $this->getInstallPath($package), false);
    }

    /**
     * Returns the base path of the package without target-dir path
     *
     * It is used for BC as getInstallPath tends to be overridden by
     * installer plugins but not getPackageBasePath
     *
     * @param  PackageInterface $package
     * @return string
     */
    protected function getPackageBasePath(PackageInterface $package)
    {
        $installPath = $this->getInstallPath($package);
        $targetDir = $package->getTargetDir();

        if ($targetDir) {
            return preg_replace('{/*'.str_replace('/', '/+', preg_quote($targetDir)).'/?$}', '', $installPath);
        }

        return $installPath;
    }

    protected function installCode(PackageInterface $package)
    {
        $downloadPath = $this->getInstallPath($package);

        return $this->downloadManager->install($package, $downloadPath);
    }

    protected function updateCode(PackageInterface $initial, PackageInterface $target)
    {
        $initialDownloadPath = $this->getInstallPath($initial);
        $targetDownloadPath = $this->getInstallPath($target);
        if ($targetDownloadPath !== $initialDownloadPath) {
            // if the target and initial dirs intersect, we force a remove + install
            // to avoid the rename wiping the target dir as part of the initial dir cleanup
            if (strpos($initialDownloadPath, $targetDownloadPath) === 0
                || strpos($targetDownloadPath, $initialDownloadPath) === 0
            ) {
                $promise = $this->removeCode($initial);
                if (!$promise instanceof PromiseInterface) {
                    $promise = \React\Promise\resolve();
                }

                $self = $this;

                return $promise->then(function () use ($self, $target) {
                    $reflMethod = new \ReflectionMethod($self, 'installCode');
                    $reflMethod->setAccessible(true);

                    // equivalent of $this->installCode($target) with php 5.3 support
                    // TODO remove this once 5.3 support is dropped
                    return $reflMethod->invoke($self, $target);
                });
            }

            $this->filesystem->rename($initialDownloadPath, $targetDownloadPath);
        }

        return $this->downloadManager->update($initial, $target, $targetDownloadPath);
    }

    protected function removeCode(PackageInterface $package)
    {
        $downloadPath = $this->getPackageBasePath($package);

        return $this->downloadManager->remove($package, $downloadPath);
    }

    protected function initializeVendorDir()
    {
        $this->filesystem->ensureDirectoryExists($this->vendorDir);
        $this->vendorDir = realpath($this->vendorDir);
    }
}
