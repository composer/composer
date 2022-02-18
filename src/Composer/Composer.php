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
    const VERSION = '@package_version@';
    const BRANCH_ALIAS_VERSION = '@package_branch_alias_version@';
    const RELEASE_DATE = '@release_date@';
    const SOURCE_VERSION = '2.3.999-dev+source';

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
    public static function getVersion(): string
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
     * @var Locker|null
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
     * @var Downloader\DownloadManager|null
     */
    private $downloadManager = null;

    /**
     * @var Installer\InstallationManager
     */
    private $installationManager;

    /**
     * @var Plugin\PluginManager|null
     */
    private $pluginManager = null;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var EventDispatcher
     */
    private $eventDispatcher;

    /**
     * @var Autoload\AutoloadGenerator|null
     */
    private $autoloadGenerator = null;

    /**
     * @var ArchiveManager|null
     */
    private $archiveManager = null;

    /**
     * @return void
     */
    public function setPackage(RootPackageInterface $package): void
    {
        $this->package = $package;
    }

    /**
     * @return RootPackageInterface
     */
    public function getPackage(): RootPackageInterface
    {
        return $this->package;
    }

    /**
     * @return void
     */
    public function setConfig(Config $config): void
    {
        $this->config = $config;
    }

    /**
     * @return Config
     */
    public function getConfig(): Config
    {
        return $this->config;
    }

    /**
     * @return void
     */
    public function setLocker(Locker $locker): void
    {
        $this->locker = $locker;
    }

    /**
     * @return ?Locker
     */
    public function getLocker(): ?Locker
    {
        return $this->locker;
    }

    /**
     * @return void
     */
    public function setLoop(Loop $loop): void
    {
        $this->loop = $loop;
    }

    /**
     * @return Loop
     */
    public function getLoop(): Loop
    {
        return $this->loop;
    }

    /**
     * @return void
     */
    public function setRepositoryManager(RepositoryManager $manager): void
    {
        $this->repositoryManager = $manager;
    }

    /**
     * @return RepositoryManager
     */
    public function getRepositoryManager(): RepositoryManager
    {
        return $this->repositoryManager;
    }

    /**
     * @return void
     */
    public function setDownloadManager(DownloadManager $manager): void
    {
        $this->downloadManager = $manager;
    }

    public function getDownloadManager(): ?DownloadManager
    {
        return $this->downloadManager;
    }

    /**
     * @return void
     */
    public function setArchiveManager(ArchiveManager $manager): void
    {
        $this->archiveManager = $manager;
    }

    public function getArchiveManager(): ?ArchiveManager
    {
        return $this->archiveManager;
    }

    /**
     * @return void
     */
    public function setInstallationManager(InstallationManager $manager): void
    {
        $this->installationManager = $manager;
    }

    /**
     * @return InstallationManager
     */
    public function getInstallationManager(): InstallationManager
    {
        return $this->installationManager;
    }

    /**
     * @return void
     */
    public function setPluginManager(PluginManager $manager): void
    {
        $this->pluginManager = $manager;
    }

    public function getPluginManager(): ?PluginManager
    {
        return $this->pluginManager;
    }

    /**
     * @return void
     */
    public function setEventDispatcher(EventDispatcher $eventDispatcher): void
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * @return EventDispatcher
     */
    public function getEventDispatcher(): EventDispatcher
    {
        return $this->eventDispatcher;
    }

    /**
     * @return void
     */
    public function setAutoloadGenerator(AutoloadGenerator $autoloadGenerator): void
    {
        $this->autoloadGenerator = $autoloadGenerator;
    }

    public function getAutoloadGenerator(): ?AutoloadGenerator
    {
        return $this->autoloadGenerator;
    }
}
