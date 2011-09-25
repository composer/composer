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
use Composer\Package\PackageLock;
use Composer\Repository\RepositoryManager;
use Composer\Installer\InstallationManager;
use Composer\Downloader\DownloadManager;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Konstantin Kudryashiv <ever.zet@gmail.com>
 */
class Composer
{
    const VERSION = '1.0.0-DEV';

    private $package;
    private $lock;

    private $rm;
    private $dm;
    private $im;

    public function setPackage(PackageInterface $package)
    {
        $this->package = $package;
    }

    public function getPackage()
    {
        return $this->package;
    }

    public function setPackageLock($lock)
    {
        $this->lock = $lock;
    }

    public function getPackageLock()
    {
        return $this->lock;
    }

    public function setRepositoryManager(RepositoryManager $manager)
    {
        $this->rm = $manager;
    }

    public function getRepositoryManager()
    {
        return $this->rm;
    }

    public function setDownloadManager(DownloadManager $manager)
    {
        $this->dm = $manager;
    }

    public function getDownloadManager()
    {
        return $this->dm;
    }

    public function setInstallationManager(InstallationManager $manager)
    {
        $this->im = $manager;
    }

    public function getInstallationManager()
    {
        return $this->im;
    }
}
