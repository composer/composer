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

use Composer\Package\RootPackageInterface;
use Composer\Package\Locker;
use Composer\Repository\RepositoryManager;
use Composer\Installer\InstallationManager;
use Composer\Plugin\PluginManager;
use Composer\Downloader\DownloadManager;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Autoload\AutoloadGenerator;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Konstantin Kudryashiv <ever.zet@gmail.com>
 * @author Nils Adermann <naderman@naderman.de>
 */
class Composer
{
    const VERSION = '1.4.2';
    const BRANCH_ALIAS_VERSION = '';
    const RELEASE_DATE = '2017-05-17 08:17:52';

    /**
     * @var Package\RootPackageInterface
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
     * @var Plugin\PluginManager
     */
    private $pluginManager;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var EventDispatcher
     */
    private $eventDispatcher;

    /**
     * @var Autoload\AutoloadGenerator
     */
    private $autoloadGenerator;

    /**
     * @param  Package\RootPackageInterface $package
     * @return void
     */
    public function setPackage(RootPackageInterface $package)
    {
        $this->package = $package;
    }

    /**
     * @return Package\RootPackageInterface
     */
    public function getPackage()
    {
        return $this->package;
    }

    /**
     * @param Config $config
     */
    public function setConfig(Config $config)
    {
        $this->config = $config;
    }

    /**
     * @return Config
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @param Package\Locker $locker
     */
    public function setLocker(Locker $locker)
    {
        $this->locker = $locker;
    }

    /**
     * @return Package\Locker
     */
    public function getLocker()
    {
        return $this->locker;
    }

    /**
     * @param Repository\RepositoryManager $manager
     */
    public function setRepositoryManager(RepositoryManager $manager)
    {
        $this->repositoryManager = $manager;
    }

    /**
     * @return Repository\RepositoryManager
     */
    public function getRepositoryManager()
    {
        return $this->repositoryManager;
    }

    /**
     * @param Downloader\DownloadManager $manager
     */
    public function setDownloadManager(DownloadManager $manager)
    {
        $this->downloadManager = $manager;
    }

    /**
     * @return Downloader\DownloadManager
     */
    public function getDownloadManager()
    {
        return $this->downloadManager;
    }

    /**
     * @param Installer\InstallationManager $manager
     */
    public function setInstallationManager(InstallationManager $manager)
    {
        $this->installationManager = $manager;
    }

    /**
     * @return Installer\InstallationManager
     */
    public function getInstallationManager()
    {
        return $this->installationManager;
    }

    /**
     * @param Plugin\PluginManager $manager
     */
    public function setPluginManager(PluginManager $manager)
    {
        $this->pluginManager = $manager;
    }

    /**
     * @return Plugin\PluginManager
     */
    public function getPluginManager()
    {
        return $this->pluginManager;
    }

    /**
     * @param EventDispatcher $eventDispatcher
     */
    public function setEventDispatcher(EventDispatcher $eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * @return EventDispatcher
     */
    public function getEventDispatcher()
    {
        return $this->eventDispatcher;
    }

    /**
     * @param Autoload\AutoloadGenerator $autoloadGenerator
     */
    public function setAutoloadGenerator(AutoloadGenerator $autoloadGenerator)
    {
        $this->autoloadGenerator = $autoloadGenerator;
    }

    /**
     * @return Autoload\AutoloadGenerator
     */
    public function getAutoloadGenerator()
    {
        return $this->autoloadGenerator;
    }
}
