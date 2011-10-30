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

/**
 * Package installation manager.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class LibraryInstaller implements InstallerInterface
{
    private $directory;
    private $downloadManager;
    private $repository;
    private $type;

    /**
     * Initializes library installer.
     *
     * @param   string                      $dir        relative path for packages home
     * @param   DownloadManager             $dm         download manager
     * @param   WritableRepositoryInterface $repository repository controller
     * @param   string                      $type       package type that this installer handles
     */
    public function __construct($directory, DownloadManager $dm, WritableRepositoryInterface $repository, $type = 'library')
    {
        $this->directory = $directory;
        $this->downloadManager = $dm;
        $this->type = $type;

        if (!is_dir($this->directory)) {
            if (file_exists($this->directory)) {
                throw new \UnexpectedValueException(
                    $this->directory.' exists and is not a directory.'
                );
            }
            if (!mkdir($this->directory, 0777, true)) {
                throw new \UnexpectedValueException(
                    $this->directory.' does not exist and could not be created.'
                );
            }
        }

        $this->repository = $repository;
    }

    /**
     * {@inheritDoc}
     */
    public function supports($packageType)
    {
        return $packageType === $this->type;
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

        $this->downloadManager->update($initial, $target, $downloadPath);
        $this->repository->removePackage($initial);
        $this->repository->addPackage(clone $target);
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall(PackageInterface $package)
    {
        if (!$this->repository->hasPackage($package)) {
            throw new \InvalidArgumentException('Package is not installed: '.$package);
        }

        $downloadPath = $this->getInstallPath($package);

        $this->downloadManager->remove($package, $downloadPath);
        $this->repository->removePackage($package);
    }

    /**
     * {@inheritDoc}
     */
    public function getInstallPath(PackageInterface $package)
    {
        $targetDir = $package->getTargetDir();
        return ($this->directory ? $this->directory.'/' : '') . $package->getName() . ($targetDir ? '/'.$targetDir : '');
    }
}
