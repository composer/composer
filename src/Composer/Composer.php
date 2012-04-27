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

    private $package;
    private $locker;

    private $repositoryManager;
    private $downloadManager;
    private $installationManager;

    public function setPackage(PackageInterface $package)
    {
        $this->package = $package;
    }

    public function getPackage()
    {
        return $this->package;
    }

    public function setConfig(Config $config)
    {
        $this->config = $config;
    }

    public function getConfig()
    {
        return $this->config;
    }

    public function setLocker(Locker $locker)
    {
        $this->locker = $locker;
    }

    public function getLocker()
    {
        return $this->locker;
    }

    public function setRepositoryManager(RepositoryManager $manager)
    {
        $this->repositoryManager = $manager;
    }

    public function getRepositoryManager()
    {
        return $this->repositoryManager;
    }

    public function setDownloadManager(DownloadManager $manager)
    {
        $this->downloadManager = $manager;
    }

    public function getDownloadManager()
    {
        return $this->downloadManager;
    }

    public function setInstallationManager(InstallationManager $manager)
    {
        $this->installationManager = $manager;
    }

    public function getInstallationManager()
    {
        return $this->installationManager;
    }
}
