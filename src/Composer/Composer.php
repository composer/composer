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
use Composer\Pcre\Preg;
use Composer\Util\Loop;
use Composer\Repository\RepositoryManager;
use Composer\Installer\InstallationManager;
use Composer\Plugin\PluginManager;
use Composer\Downloader\DownloadManager;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Autoload\AutoloadGenerator;
use Composer\Package\Archiver\ArchiveManager;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Konstantin Kudryashiv <ever.zet@gmail.com>
 * @author Nils Adermann <naderman@naderman.de>
 */
class Composer
{
    /*
     * Examples of the following constants in the various configurations they can be in
     *
     * releases (phar):
     * const VERSION = '1.8.2';
     * const BRANCH_ALIAS_VERSION = '';
     * const RELEASE_DATE = '2019-01-29 15:00:53';
     * const SOURCE_VERSION = '';
     *
     * snapshot builds (phar):
     * const VERSION = 'd3873a05650e168251067d9648845c220c50e2d7';
     * const BRANCH_ALIAS_VERSION = '1.9-dev';
     * const RELEASE_DATE = '2019-02-20 07:43:56';
     * const SOURCE_VERSION = '';
     *
     * source (git clone):
     * const VERSION = '@package_version@';
     * const BRANCH_ALIAS_VERSION = '@package_branch_alias_version@';
     * const RELEASE_DATE = '@release_date@';
     * const SOURCE_VERSION = '1.8-dev+source';
     */
    const VERSION = '2.2.4';
    const BRANCH_ALIAS_VERSION = '';
    const RELEASE_DATE = '2022-01-08 12:30:42';
    const SOURCE_VERSION = '';

    /**
     * Version number of the internal composer-runtime-api package
     *
     * This is used to version features available to projects at runtime
     * like the platform-check file, the Composer\InstalledVersions class
     * and possibly others in the future.
     *
     * @var string
     */
    const RUNTIME_API_VERSION = '2.2.2';

    /**
     * @return string
     */
    public static function getVersion()
    {
        // no replacement done, this must be a source checkout
        if (self::VERSION === '@package_version'.'@') {
            return self::SOURCE_VERSION;
        }

        // we have a branch alias and version is a commit id, this must be a snapshot build
        if (self::BRANCH_ALIAS_VERSION !== '' && Preg::isMatch('{^[a-f0-9]{40}$}', self::VERSION)) {
            return self::BRANCH_ALIAS_VERSION.'+'.self::VERSION;
        }

        return self::VERSION;
    }

    /**
     * @var RootPackageInterface
     */
    private $package;

    /**
     * @var ?Locker
     */
    private $locker = null;

    /**
     * @var Loop
     */
    private $loop;

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
     * @var ArchiveManager
     */
    private $archiveManager;

    /**
     * @return void
     */
    public function setPackage(RootPackageInterface $package)
    {
        $this->package = $package;
    }

    /**
     * @return RootPackageInterface
     */
    public function getPackage()
    {
        return $this->package;
    }

    /**
     * @return void
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
     * @return void
     */
    public function setLocker(Locker $locker)
    {
        $this->locker = $locker;
    }

    /**
     * @return ?Locker
     */
    public function getLocker()
    {
        return $this->locker;
    }

    /**
     * @return void
     */
    public function setLoop(Loop $loop)
    {
        $this->loop = $loop;
    }

    /**
     * @return Loop
     */
    public function getLoop()
    {
        return $this->loop;
    }

    /**
     * @return void
     */
    public function setRepositoryManager(RepositoryManager $manager)
    {
        $this->repositoryManager = $manager;
    }

    /**
     * @return RepositoryManager
     */
    public function getRepositoryManager()
    {
        return $this->repositoryManager;
    }

    /**
     * @return void
     */
    public function setDownloadManager(DownloadManager $manager)
    {
        $this->downloadManager = $manager;
    }

    /**
     * @return DownloadManager
     */
    public function getDownloadManager()
    {
        return $this->downloadManager;
    }

    /**
     * @return void
     */
    public function setArchiveManager(ArchiveManager $manager)
    {
        $this->archiveManager = $manager;
    }

    /**
     * @return ArchiveManager
     */
    public function getArchiveManager()
    {
        return $this->archiveManager;
    }

    /**
     * @return void
     */
    public function setInstallationManager(InstallationManager $manager)
    {
        $this->installationManager = $manager;
    }

    /**
     * @return InstallationManager
     */
    public function getInstallationManager()
    {
        return $this->installationManager;
    }

    /**
     * @return void
     */
    public function setPluginManager(PluginManager $manager)
    {
        $this->pluginManager = $manager;
    }

    /**
     * @return PluginManager
     */
    public function getPluginManager()
    {
        return $this->pluginManager;
    }

    /**
     * @return void
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
     * @return void
     */
    public function setAutoloadGenerator(AutoloadGenerator $autoloadGenerator)
    {
        $this->autoloadGenerator = $autoloadGenerator;
    }

    /**
     * @return AutoloadGenerator
     */
    public function getAutoloadGenerator()
    {
        return $this->autoloadGenerator;
    }
}
