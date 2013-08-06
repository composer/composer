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
use Composer\Package\Archiver;
use Composer\Repository\ComposerRepository;
use Composer\Repository\RepositoryManager;
use Composer\Util\ProcessExecutor;
use Composer\Util\RemoteFilesystem;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Composer\Script\EventDispatcher;
use Composer\Autoload\AutoloadGenerator;

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
        // determine home and cache dirs
        $home = getenv('COMPOSER_HOME');
        $cacheDir = getenv('COMPOSER_CACHE_DIR');
        if (!$home) {
            if (defined('PHP_WINDOWS_VERSION_MAJOR')) {
                if (!getenv('APPDATA')) {
                    throw new \RuntimeException('The APPDATA or COMPOSER_HOME environment variable must be set for composer to run correctly');
                }
                $home = strtr(getenv('APPDATA'), '\\', '/') . '/Composer';
            } else {
                if (!getenv('HOME')) {
                    throw new \RuntimeException('The HOME or COMPOSER_HOME environment variable must be set for composer to run correctly');
                }
                $home = rtrim(getenv('HOME'), '/') . '/.composer';
            }
        }
        if (!$cacheDir) {
            if (defined('PHP_WINDOWS_VERSION_MAJOR')) {
                if ($cacheDir = getenv('LOCALAPPDATA')) {
                    $cacheDir .= '/Composer';
                } else {
                    $cacheDir = getenv('APPDATA') . '/Composer/cache';
                }
                $cacheDir = strtr($cacheDir, '\\', '/');
            } else {
                $cacheDir = $home.'/cache';
            }
        }

        // Protect directory against web access. Since HOME could be
        // the www-data's user home and be web-accessible it is a
        // potential security risk
        foreach (array($home, $cacheDir) as $dir) {
            if (!file_exists($dir . '/.htaccess')) {
                if (!is_dir($dir)) {
                    @mkdir($dir, 0777, true);
                }
                @file_put_contents($dir . '/.htaccess', 'Deny from all');
            }
        }

        $config = new Config();

        // add dirs to the config
        $config->merge(array('config' => array('home' => $home, 'cache-dir' => $cacheDir)));

        $file = new JsonFile($home.'/config.json');
        if ($file->exists()) {
            $config->merge($file->read());
        }
        $config->setConfigSource(new JsonConfigSource($file));

        // move old cache dirs to the new locations
        $legacyPaths = array(
            'cache-repo-dir' => array('/cache' => '/http*', '/cache.svn' => '/*', '/cache.github' => '/*'),
            'cache-vcs-dir' => array('/cache.git' => '/*', '/cache.hg' => '/*'),
            'cache-files-dir' => array('/cache.files' => '/*'),
        );
        foreach ($legacyPaths as $key => $oldPaths) {
            foreach ($oldPaths as $oldPath => $match) {
                $dir = $config->get($key);
                if ('/cache.github' === $oldPath) {
                    $dir .= '/github.com';
                }
                $oldPath = $config->get('home').$oldPath;
                $oldPathMatch = $oldPath . $match;
                if (is_dir($oldPath) && $dir !== $oldPath) {
                    if (!is_dir($dir)) {
                        if (!@mkdir($dir, 0777, true)) {
                            continue;
                        }
                    }
                    if (is_array($children = glob($oldPathMatch))) {
                        foreach ($children as $child) {
                            @rename($child, $dir.'/'.basename($child));
                        }
                    }
                    @rmdir($oldPath);
                }
            }
        }

        return $config;
    }

    public static function getComposerFile()
    {
        return trim(getenv('COMPOSER')) ?: 'composer.json';
    }

    public static function createAdditionalStyles()
    {
        return array(
            'highlight' => new OutputFormatterStyle('red'),
            'warning' => new OutputFormatterStyle('black', 'yellow'),
        );
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
            $localConfig = static::getComposerFile();
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
                if (!preg_match('{^[a-z0-9]+$}', $token)) {
                    throw new \UnexpectedValueException('Your github oauth token for '.$domain.' contains invalid characters: "'.$token.'"');
                }
                $io->setAuthentication($domain, $token, 'x-oauth-basic');
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
        $im = $this->createInstallationManager();

        // initialize composer
        $composer = new Composer();
        $composer->setConfig($config);
        $composer->setPackage($package);
        $composer->setRepositoryManager($rm);
        $composer->setDownloadManager($dm);
        $composer->setInstallationManager($im);

        // initialize event dispatcher
        $dispatcher = new EventDispatcher($composer, $io);
        $composer->setEventDispatcher($dispatcher);

        // initialize autoload generator
        $generator = new AutoloadGenerator($dispatcher);
        $composer->setAutoloadGenerator($generator);

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
        $rm->setRepositoryClass('artifact', 'Composer\Repository\ArtifactRepository');

        return $rm;
    }

    /**
     * @param Repository\RepositoryManager $rm
     * @param string                       $vendorDir
     */
    protected function addLocalRepository(RepositoryManager $rm, $vendorDir)
    {
        $rm->setLocalRepository(new Repository\InstalledFilesystemRepository(new JsonFile($vendorDir.'/composer/installed.json')));
    }

    /**
     * @param  IO\IOInterface             $io
     * @param  Config                     $config
     * @return Downloader\DownloadManager
     */
    public function createDownloadManager(IOInterface $io, Config $config)
    {
        $cache = null;
        if ($config->get('cache-files-ttl') > 0) {
            $cache = new Cache($io, $config->get('cache-files-dir'), 'a-z0-9_./');
        }

        $dm = new Downloader\DownloadManager();
        $dm->setDownloader('git', new Downloader\GitDownloader($io, $config));
        $dm->setDownloader('svn', new Downloader\SvnDownloader($io, $config));
        $dm->setDownloader('hg', new Downloader\HgDownloader($io, $config));
        $dm->setDownloader('zip', new Downloader\ZipDownloader($io, $config, $cache));
        $dm->setDownloader('tar', new Downloader\TarDownloader($io, $config, $cache));
        $dm->setDownloader('phar', new Downloader\PharDownloader($io, $config, $cache));
        $dm->setDownloader('file', new Downloader\FileDownloader($io, $config, $cache));

        return $dm;
    }

    /**
     * @param Config                     $config  The configuration
     * @param Downloader\DownloadManager $dm      Manager use to download sources
     *
     * @return Archiver\ArchiveManager
     */
    public function createArchiveManager(Config $config, Downloader\DownloadManager $dm = null)
    {
        if (null === $dm) {
            $dm = $this->createDownloadManager(new IO\NullIO(), $config);
        }

        $am = new Archiver\ArchiveManager($dm);
        $am->addArchiver(new Archiver\PharArchiver);

        return $am;
    }

    /**
     * @return Installer\InstallationManager
     */
    protected function createInstallationManager()
    {
        return new Installer\InstallationManager();
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
        $repo = $rm->getLocalRepository();
        foreach ($repo->getPackages() as $package) {
            if (!$im->isPackageInstalled($repo, $package)) {
                $repo->removePackage($package);
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
