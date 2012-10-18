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

use Composer\Config\JsonConfigSource;
use Composer\Json\JsonFile;
use Composer\IO\IOInterface;
use Composer\Repository\ComposerRepository;
use Composer\Repository\RepositoryManager;
use Composer\Util\ProcessExecutor;
use Composer\Util\RemoteFilesystem;

/**
 * Creates a configured instance of composer.
 *
 * @author Ryan Weaver <ryan@knplabs.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Igor Wiedler <igor@wiedler.ch>
 */
class Factory
{
    /**
     * @return Config
     */
    public static function createConfig()
    {
        // load main Composer configuration
        if (!$home = getenv('COMPOSER_HOME')) {
            if (defined('PHP_WINDOWS_VERSION_MAJOR')) {
                $home = getenv('APPDATA') . '/Composer';
            } else {
                $home = rtrim(getenv('HOME'), '/') . '/.composer';
            }
        }

        // Protect directory against web access
        if (!file_exists($home . '/.htaccess')) {
            if (!is_dir($home)) {
                @mkdir($home, 0777, true);
            }
            @file_put_contents($home . '/.htaccess', 'Deny from all');
        }

        $config = new Config();

        // add home dir to the config
        $config->merge(array('config' => array('home' => $home)));

        $file = new JsonFile($home.'/config.json');
        if ($file->exists()) {
            $config->merge($file->read());
        }
        $config->setConfigSource(new JsonConfigSource($file));

        return $config;
    }

    public function getComposerFile()
    {
        return getenv('COMPOSER') ?: 'composer.json';
    }

    public static function createDefaultRepositories(IOInterface $io = null, Config $config = null, RepositoryManager $rm = null)
    {
        $repos = array();

        if (!$config) {
            $config = static::createConfig();
        }
        if (!$rm) {
            if (!$io) {
                throw new \InvalidArgumentException('This function requires either an IOInterface or a RepositoryManager');
            }
            $factory = new static;
            $rm = $factory->createRepositoryManager($io, $config);
        }

        foreach ($config->getRepositories() as $index => $repo) {
            if (!is_array($repo)) {
                throw new \UnexpectedValueException('Repository '.$index.' ('.json_encode($repo).') should be an array, '.gettype($repo).' given');
            }
            if (!isset($repo['type'])) {
                throw new \UnexpectedValueException('Repository '.$index.' ('.json_encode($repo).') must have a type defined');
            }
            $name = is_int($index) && isset($repo['url']) ? preg_replace('{^https?://}i', '', $repo['url']) : $index;
            while (isset($repos[$name])) {
                $name .= '2';
            }
            $repos[$name] = $rm->createRepository($repo['type'], $repo);
        }

        return $repos;
    }

    /**
     * Creates a Composer instance
     *
     * @param IOInterface       $io          IO instance
     * @param array|string|null $localConfig either a configuration array or a filename to read from, if null it will
     *                                       read from the default filename
     * @throws \InvalidArgumentException
     * @return Composer
     */
    public function createComposer(IOInterface $io, $localConfig = null)
    {
        // load Composer configuration
        if (null === $localConfig) {
            $localConfig = $this->getComposerFile();
        }

        if (is_string($localConfig)) {
            $composerFile = $localConfig;
            $file = new JsonFile($localConfig, new RemoteFilesystem($io));

            if (!$file->exists()) {
                if ($localConfig === 'composer.json') {
                    $message = 'Composer could not find a composer.json file in '.getcwd();
                } else {
                    $message = 'Composer could not find the config file: '.$localConfig;
                }
                $instructions = 'To initialize a project, please create a composer.json file as described in the http://getcomposer.org/ "Getting Started" section';
                throw new \InvalidArgumentException($message.PHP_EOL.$instructions);
            }

            $file->validateSchema(JsonFile::LAX_SCHEMA);
            $localConfig = $file->read();
        }

        // Configuration defaults
        $config = static::createConfig();
        $config->merge($localConfig);

        // reload oauth token from config if available
        if ($tokens = $config->get('github-oauth')) {
            foreach ($tokens as $domain => $token) {
                $io->setAuthorization($domain, $token, 'x-oauth-basic');
            }
        }

        $vendorDir = $config->get('vendor-dir');
        $binDir = $config->get('bin-dir');

        // setup process timeout
        ProcessExecutor::setTimeout((int) $config->get('process-timeout'));

        // initialize repository manager
        $rm = $this->createRepositoryManager($io, $config);

        // load local repository
        $this->addLocalRepository($rm, $vendorDir);

        // load package
        $loader  = new Package\Loader\RootPackageLoader($rm, $config);
        $package = $loader->load($localConfig);

        // initialize download manager
        $dm = $this->createDownloadManager($io, $config);

        // initialize installation manager
        $im = $this->createInstallationManager($config);

        // initialize composer
        $composer = new Composer();
        $composer->setConfig($config);
        $composer->setPackage($package);
        $composer->setRepositoryManager($rm);
        $composer->setDownloadManager($dm);
        $composer->setInstallationManager($im);

        // add installers to the manager
        $this->createDefaultInstallers($im, $composer, $io);

        // purge packages if they have been deleted on the filesystem
        $this->purgePackages($rm, $im);

        // init locker if possible
        if (isset($composerFile)) {
            $lockFile = "json" === pathinfo($composerFile, PATHINFO_EXTENSION)
                ? substr($composerFile, 0, -4).'lock'
                : $composerFile . '.lock';
            $locker = new Package\Locker(new JsonFile($lockFile, new RemoteFilesystem($io)), $rm, $im, md5_file($composerFile));
            $composer->setLocker($locker);
        }

        return $composer;
    }

    /**
     * @param  IOInterface                  $io
     * @param  Config                       $config
     * @return Repository\RepositoryManager
     */
    protected function createRepositoryManager(IOInterface $io, Config $config)
    {
        $rm = new RepositoryManager($io, $config);
        $rm->setRepositoryClass('composer', 'Composer\Repository\ComposerRepository');
        $rm->setRepositoryClass('vcs', 'Composer\Repository\VcsRepository');
        $rm->setRepositoryClass('package', 'Composer\Repository\PackageRepository');
        $rm->setRepositoryClass('pear', 'Composer\Repository\PearRepository');
        $rm->setRepositoryClass('git', 'Composer\Repository\VcsRepository');
        $rm->setRepositoryClass('svn', 'Composer\Repository\VcsRepository');
        $rm->setRepositoryClass('hg', 'Composer\Repository\VcsRepository');

        return $rm;
    }

    /**
     * @param Repository\RepositoryManager $rm
     * @param string                       $vendorDir
     */
    protected function addLocalRepository(RepositoryManager $rm, $vendorDir)
    {
        $rm->setLocalRepository(new Repository\InstalledFilesystemRepository(new JsonFile($vendorDir.'/composer/installed.json')));
        $rm->setLocalDevRepository(new Repository\InstalledFilesystemRepository(new JsonFile($vendorDir.'/composer/installed_dev.json')));
    }

    /**
     * @param  IO\IOInterface             $io
     * @return Downloader\DownloadManager
     */
    public function createDownloadManager(IOInterface $io, Config $config)
    {
        $dm = new Downloader\DownloadManager();
        $dm->setDownloader('git', new Downloader\GitDownloader($io, $config));
        $dm->setDownloader('svn', new Downloader\SvnDownloader($io, $config));
        $dm->setDownloader('hg', new Downloader\HgDownloader($io, $config));
        $dm->setDownloader('zip', new Downloader\ZipDownloader($io));
        $dm->setDownloader('tar', new Downloader\TarDownloader($io));
        $dm->setDownloader('phar', new Downloader\PharDownloader($io));
        $dm->setDownloader('file', new Downloader\FileDownloader($io));

        return $dm;
    }

    /**
     * @param  Config                        $config
     * @return Installer\InstallationManager
     */
    protected function createInstallationManager(Config $config)
    {
        return new Installer\InstallationManager($config->get('vendor-dir'));
    }

    /**
     * @param Installer\InstallationManager $im
     * @param Composer                      $composer
     * @param IO\IOInterface                $io
     */
    protected function createDefaultInstallers(Installer\InstallationManager $im, Composer $composer, IOInterface $io)
    {
        $im->addInstaller(new Installer\LibraryInstaller($io, $composer, null));
        $im->addInstaller(new Installer\PearInstaller($io, $composer, 'pear-library'));
        $im->addInstaller(new Installer\InstallerInstaller($io, $composer));
        $im->addInstaller(new Installer\MetapackageInstaller($io));
    }

    /**
     * @param Repository\RepositoryManager  $rm
     * @param Installer\InstallationManager $im
     */
    protected function purgePackages(Repository\RepositoryManager $rm, Installer\InstallationManager $im)
    {
        foreach ($rm->getLocalRepositories() as $repo) {
            /* @var $repo   Repository\WritableRepositoryInterface */
            foreach ($repo->getPackages() as $package) {
                if (!$im->isPackageInstalled($repo, $package)) {
                    $repo->removePackage($package);
                }
            }
        }
    }

    /**
     * @param IOInterface $io     IO instance
     * @param mixed       $config either a configuration array or a filename to read from, if null it will read from
     *                             the default filename
     * @return Composer
     */
    public static function create(IOInterface $io, $config = null)
    {
        $factory = new static();

        return $factory->createComposer($io, $config);
    }
}
