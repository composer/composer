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

use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\Package\PackageInterface;
use Composer\Downloader\DownloadManager;

/**
 * Project Installer is used to install a single package into a directory as
 * root project.
 *
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 */
class ProjectInstaller implements InstallerInterface
{
    private $installPath;
    private $downloadManager;

    public function __construct($installPath, DownloadManager $dm)
    {
        $this->installPath = $installPath;
        $this->downloadManager = $dm;
    }

    /**
     * Decides if the installer supports the given type
     *
     * @param   string  $packageType
     * @return  Boolean
     */
    public function supports($packageType)
    {
        return true;
    }

    /**
     * Checks that provided package is installed.
     *
     * @param   PackageInterface    $package    package instance
     *
     * @return  Boolean
     */
    public function isInstalled(PackageInterface $package)
    {
        return false;
    }

    /**
     * Installs specific package.
     *
     * @param   PackageInterface    $package    package instance
     */
    public function install(PackageInterface $package)
    {
        $installPath = $this->installPath;
        if (file_exists($installPath)) {
            throw new \InvalidArgumentException("Project directory $installPath already exists.");
        }
        if (!file_exists(dirname($installPath))) {
            throw new \InvalidArgumentException("Project root " . dirname($installPath) . " does not exist.");
        }
        mkdir($installPath, 0777);
        $this->downloadManager->download($package, $installPath);
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
        throw new \InvalidArgumentException("not supported");
    }

    /**
     * Uninstalls specific package.
     *
     * @param   PackageInterface    $package    package instance
     */
    public function uninstall(PackageInterface $package)
    {
        throw new \InvalidArgumentException("not supported");
    }

    /**
     * Returns the installation path of a package
     *
     * @param   PackageInterface    $package
     * @return  string path
     */
    public function getInstallPath(PackageInterface $package)
    {
        return $this->installPath;
    }
}

