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
    private $dir;
    private $dm;
    private $repository;

    /**
     * Initializes library installer.
     *
     * @param   string                      $dir        relative path for packages home
     * @param   DownloadManager             $dm         download manager
     * @param   WritableRepositoryInterface $repository repository controller
     */
    public function __construct($dir, DownloadManager $dm, WritableRepositoryInterface $repository)
    {
        $this->dir = $dir;
        $this->dm  = $dm;

        if (!is_dir($this->dir)) {
            if (file_exists($this->dir)) {
                throw new \UnexpectedValueException(
                    $this->dir.' exists and is not a directory.'
                );
            }
            if (!mkdir($this->dir, 0777, true)) {
                throw new \UnexpectedValueException(
                    $this->dir.' does not exist and could not be created.'
                );
            }
        }

        $this->repository = $repository;
    }

    /**
     * Executes specific solver operation.
     *
     * @param   OperationInterface  $operation  solver operation instance
     */
    public function executeOperation(OperationInterface $operation)
    {
        $method = $operation->getJobType();

        if ('update' === $method) {
            $this->$method($operation->getInitialPackage(), $operation->getTargetPackage());
        } else {
            $this->$method($operation->getPackage());
        }
    }

    /**
     * Checks that specific package is installed.
     *
     * @param   PackageInterface    $package    package instance
     *
     * @return  Boolean
     */
    public function isInstalled(PackageInterface $package)
    {
        return $this->repository->hasPackage($package);
    }

    /**
     * Installs specific package.
     *
     * @param   PackageInterface    $package    package instance
     *
     * @throws  InvalidArgumentException        if provided package have no urls to download from
     */
    public function install(PackageInterface $package)
    {
        $downloadPath = $this->dir.DIRECTORY_SEPARATOR.$package->getName();

        $this->dm->download($package, $downloadPath);
        $this->repository->addPackage($package);
    }

    /**
     * Updates specific package.
     *
     * @param   PackageInterface    $initial    already installed package version
     * @param   PackageInterface    $target     updated version
     *
     * @throws  InvalidArgumentException        if $from package is not installed
     */
    public function update(PackageInterface $initial, PackageInterface $target)
    {
        if (!$this->repository->hasPackage($initial)) {
            throw new \InvalidArgumentException('Package is not installed: '.$initial);
        }

        $downloadPath = $this->dir.DIRECTORY_SEPARATOR.$initial->getName();

        $this->dm->update($initial, $target, $downloadPath);
        $this->repository->removePackage($initial);
        $this->repository->addPackage($target);
    }

    /**
     * Uninstalls specific package.
     *
     * @param   PackageInterface    $package    package instance
     *
     * @throws  InvalidArgumentException        if package is not installed
     */
    public function uninstall(PackageInterface $package)
    {
        if (!$this->repository->hasPackage($package)) {
            throw new \InvalidArgumentException('Package is not installed: '.$package);
        }

        $downloadPath = $this->dir.DIRECTORY_SEPARATOR.$package->getName();

        $this->dm->remove($package, $downloadPath);
        $this->repository->removePackage($package);
    }
}
