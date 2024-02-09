<?php declare(strict_types=1);

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

use Composer\Package\Locker;
use Composer\Pcre\Preg;
use Composer\Plugin\PluginManager;
use Composer\Downloader\DownloadManager;
use Composer\Autoload\AutoloadGenerator;
use Composer\Package\Archiver\ArchiveManager;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Konstantin Kudryashiv <ever.zet@gmail.com>
 * @author Nils Adermann <naderman@naderman.de>
 */
class Composer extends PartialComposer
{
    /*
     * Examples of the following constants in the various configurations they can be in
     *
     * You are probably better off using Composer::getVersion() though as that will always return something usable
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
     *
     * @see getVersion()
     */
    public const VERSION = '2.7.1';
    public const BRANCH_ALIAS_VERSION = '';
    public const RELEASE_DATE = '2024-02-09 15:26:28';
    public const SOURCE_VERSION = '';

    /**
     * Version number of the internal composer-runtime-api package
     *
     * This is used to version features available to projects at runtime
     * like the platform-check file, the Composer\InstalledVersions class
     * and possibly others in the future.
     *
     * @var string
     */
    public const RUNTIME_API_VERSION = '2.2.2';

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
     * @var Locker
     */
    private $locker;

    /**
     * @var Downloader\DownloadManager
     */
    private $downloadManager;

    /**
     * @var Plugin\PluginManager
     */
    private $pluginManager;

    /**
     * @var Autoload\AutoloadGenerator
     */
    private $autoloadGenerator;

    /**
     * @var ArchiveManager
     */
    private $archiveManager;

    public function setLocker(Locker $locker): void
    {
        $this->locker = $locker;
    }

    public function getLocker(): Locker
    {
        return $this->locker;
    }

    public function setDownloadManager(DownloadManager $manager): void
    {
        $this->downloadManager = $manager;
    }

    public function getDownloadManager(): DownloadManager
    {
        return $this->downloadManager;
    }

    public function setArchiveManager(ArchiveManager $manager): void
    {
        $this->archiveManager = $manager;
    }

    public function getArchiveManager(): ArchiveManager
    {
        return $this->archiveManager;
    }

    public function setPluginManager(PluginManager $manager): void
    {
        $this->pluginManager = $manager;
    }

    public function getPluginManager(): PluginManager
    {
        return $this->pluginManager;
    }

    public function setAutoloadGenerator(AutoloadGenerator $autoloadGenerator): void
    {
        $this->autoloadGenerator = $autoloadGenerator;
    }

    public function getAutoloadGenerator(): AutoloadGenerator
    {
        return $this->autoloadGenerator;
    }
}
