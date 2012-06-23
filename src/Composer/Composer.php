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

namespace Composer;

use Composer\Package\PackageInterface;
use Composer\Package\Locker;
use Composer\Repository\RepositoryManager;
use Composer\Installer\InstallationManager;
use Composer\Downloader\DownloadManager;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Konstantin Kudryashiv <ever.zet@gmail.com>
 */
class Composer
{
    const VERSION = '@package_version@';

    /**
     * @var Package\PackageInterface
     */
    private $package;

    /**
     * @var Locker
     */
    private $locker;

    /**
     * @var Repository\RepositoryManager
     */
    private $repositoryManager;

    /**
     * @var Downloader\DownloadManager
     */
    private $downloadManager;

    /**
     * @var Installer\InstallationManager
     */
    private $installationManager;

    /**
     * @var Config
     */
    private $config;

    /**
     * @param   Package\PackageInterface    $package
     * @return  void
     */
    public function setPackage(PackageInterface $package)
    {
        $this->package = $package;
    }

    /**
     * @return  Package\PackageInterface
     */
    public function getPackage()
    {
        return $this->package;
    }

    /**
     * @param   Config  $config
     */
    public function setConfig(Config $config)
    {
        $this->config = $config;
    }

    /**
     * @return  Config
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @param   Package\Locker  $locker
     */
    public function setLocker(Locker $locker)
    {
        $this->locker = $locker;
    }

    /**
     * @return  Package\Locker
     */
    public function getLocker()
    {
        return $this->locker;
    }

    /**
     * @param   Repository\RepositoryManager    $manager
     */
    public function setRepositoryManager(RepositoryManager $manager)
    {
        $this->repositoryManager = $manager;
    }

    /**
     * @return  Repository\RepositoryManager
     */
    public function getRepositoryManager()
    {
        return $this->repositoryManager;
    }

    /**
     * @param   Downloader\DownloadManager  $manager
     */
    public function setDownloadManager(DownloadManager $manager)
    {
        $this->downloadManager = $manager;
    }

    /**
     * @return  Downloader\DownloadManager
     */
    public function getDownloadManager()
    {
        return $this->downloadManager;
    }

    /**
     * @param   Installer\InstallationManager   $manager
     */
    public function setInstallationManager(InstallationManager $manager)
    {
        $this->installationManager = $manager;
    }

    /**
     * @return  Installer\InstallationManager
     */
    public function getInstallationManager()
    {
        return $this->installationManager;
    }
}
