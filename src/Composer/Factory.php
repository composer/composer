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
use Composer\Package\Version\VersionGuesser;
use Composer\Package\RootPackageInterface;
use Composer\Repository\RepositoryManager;
use Composer\Repository\RepositoryFactory;
use Composer\Util\Filesystem;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;
use Composer\Util\HttpDownloader;
use Composer\Util\Loop;
use Composer\Util\Silencer;
use Composer\Plugin\PluginEvents;
use Composer\EventDispatcher\Event;
use Seld\JsonLint\DuplicateKeyException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Output\ConsoleOutput;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Autoload\AutoloadGenerator;
use Composer\Package\Version\VersionParser;
use Composer\Downloader\TransportException;
use Composer\Json\JsonValidationException;
use Composer\Repository\InstalledRepositoryInterface;
use Seld\JsonLint\JsonParser;

/**
 * Creates a configured instance of composer.
 *
 * @author Ryan Weaver <ryan@knplabs.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Igor Wiedler <igor@wiedler.ch>
 * @author Nils Adermann <naderman@naderman.de>
 */
class Factory
{
    /**
     * @throws \RuntimeException
     * @return string
     */
    protected static function getHomeDir()
    {
        $home = Platform::getEnv('COMPOSER_HOME');
        if ($home) {
            return $home;
        }

        if (Platform::isWindows()) {
            if (!Platform::getEnv('APPDATA')) {
                throw new \RuntimeException('The APPDATA or COMPOSER_HOME environment variable must be set for composer to run correctly');
            }

            return rtrim(strtr(Platform::getEnv('APPDATA'), '\\', '/'), '/') . '/Composer';
        }

        $userDir = self::getUserDir();
        $dirs = array();

        if (self::useXdg()) {
            // XDG Base Directory Specifications
            $xdgConfig = Platform::getEnv('XDG_CONFIG_HOME');
            if (!$xdgConfig) {
                $xdgConfig = $userDir . '/.config';
            }

            $dirs[] = $xdgConfig . '/composer';
        }

        $dirs[] = $userDir . '/.composer';

        // select first dir which exists of: $XDG_CONFIG_HOME/composer or ~/.composer
        foreach ($dirs as $dir) {
            if (Silencer::call('is_dir', $dir)) {
                return $dir;
            }
        }

        // if none exists, we default to first defined one (XDG one if system uses it, or ~/.composer otherwise)
        return $dirs[0];
    }

    /**
     * @param  string $home
     * @return string
     */
    protected static function getCacheDir($home)
    {
        $cacheDir = Platform::getEnv('COMPOSER_CACHE_DIR');
        if ($cacheDir) {
            return $cacheDir;
        }

        $homeEnv = Platform::getEnv('COMPOSER_HOME');
        if ($homeEnv) {
            return $homeEnv . '/cache';
        }

        if (Platform::isWindows()) {
            if ($cacheDir = Platform::getEnv('LOCALAPPDATA')) {
                $cacheDir .= '/Composer';
            } else {
                $cacheDir = $home . '/cache';
            }

            return rtrim(strtr($cacheDir, '\\', '/'), '/');
        }

        $userDir = self::getUserDir();
        if (PHP_OS === 'Darwin') {
            // Migrate existing cache dir in old location if present
            if (is_dir($home . '/cache') && !is_dir($userDir . '/Library/Caches/composer')) {
                Silencer::call('rename', $home . '/cache', $userDir . '/Library/Caches/composer');
            }

            return $userDir . '/Library/Caches/composer';
        }

        if ($home === $userDir . '/.composer' && is_dir($home . '/cache')) {
            return $home . '/cache';
        }

        if (self::useXdg()) {
            $xdgCache = Platform::getEnv('XDG_CACHE_HOME') ?: $userDir . '/.cache';

            return $xdgCache . '/composer';
        }

        return $home . '/cache';
    }

    /**
     * @param  string $home
     * @return string
     */
    protected static function getDataDir($home)
    {
        $homeEnv = Platform::getEnv('COMPOSER_HOME');
        if ($homeEnv) {
            return $homeEnv;
        }

        if (Platform::isWindows()) {
            return strtr($home, '\\', '/');
        }

        $userDir = self::getUserDir();
        if ($home !== $userDir . '/.composer' && self::useXdg()) {
            $xdgData = Platform::getEnv('XDG_DATA_HOME') ?: $userDir . '/.local/share';

            return $xdgData . '/composer';
        }

        return $home;
    }

    /**
     * @param string|null $cwd
     *
     * @return Config
     */
    public static function createConfig(IOInterface $io = null, $cwd = null)
    {
        $cwd = $cwd ?: (string) getcwd();

        $config = new Config(true, $cwd);

        // determine and add main dirs to the config
        $home = self::getHomeDir();
        $config->merge(array('config' => array(
            'home' => $home,
            'cache-dir' => self::getCacheDir($home),
            'data-dir' => self::getDataDir($home),
        )), Config::SOURCE_DEFAULT);

        // load global config
        $file = new JsonFile($config->get('home').'/config.json');
        if ($file->exists()) {
            if ($io && $io->isDebug()) {
                $io->writeError('Loading config file ' . $file->getPath());
            }
            $config->merge($file->read(), $file->getPath());
        }
        $config->setConfigSource(new JsonConfigSource($file));

        $htaccessProtect = (bool) $config->get('htaccess-protect');
        if ($htaccessProtect) {
            // Protect directory against web access. Since HOME could be
            // the www-data's user home and be web-accessible it is a
            // potential security risk
            $dirs = array($config->get('home'), $config->get('cache-dir'), $config->get('data-dir'));
            foreach ($dirs as $dir) {
                if (!file_exists($dir . '/.htaccess')) {
                    if (!is_dir($dir)) {
                        Silencer::call('mkdir', $dir, 0777, true);
                    }
                    Silencer::call('file_put_contents', $dir . '/.htaccess', 'Deny from all');
                }
            }
        }

        // load global auth file
        $file = new JsonFile($config->get('home').'/auth.json');
        if ($file->exists()) {
            if ($io && $io->isDebug()) {
                $io->writeError('Loading config file ' . $file->getPath());
            }
            $config->merge(array('config' => $file->read()), $file->getPath());
        }
        $config->setAuthConfigSource(new JsonConfigSource($file, true));

        // load COMPOSER_AUTH environment variable if set
        if ($composerAuthEnv = Platform::getEnv('COMPOSER_AUTH')) {
            $authData = json_decode($composerAuthEnv, true);

            if (null === $authData) {
                if ($io) {
                    $io->writeError('<error>COMPOSER_AUTH environment variable is malformed, should be a valid JSON object</error>');
                }
            } else {
                if ($io && $io->isDebug()) {
                    $io->writeError('Loading auth config from COMPOSER_AUTH');
                }
                $config->merge(array('config' => $authData), 'COMPOSER_AUTH');
            }
        }

        return $config;
    }

    /**
     * @return string
     */
    public static function getComposerFile()
    {
        return trim(Platform::getEnv('COMPOSER')) ?: './composer.json';
    }

    /**
     * @param string $composerFile
     *
     * @return string
     */
    public static function getLockFile($composerFile)
    {
        return "json" === pathinfo($composerFile, PATHINFO_EXTENSION)
                ? substr($composerFile, 0, -4).'lock'
                : $composerFile . '.lock';
    }

    /**
     * @return array{highlight: OutputFormatterStyle, warning: OutputFormatterStyle}
     */
    public static function createAdditionalStyles()
    {
        return array(
            'highlight' => new OutputFormatterStyle('red'),
            'warning' => new OutputFormatterStyle('black', 'yellow'),
        );
    }

    /**
     * Creates a ConsoleOutput instance
     *
     * @return ConsoleOutput
     */
    public static function createOutput()
    {
        $styles = self::createAdditionalStyles();
        $formatter = new OutputFormatter(false, $styles);

        return new ConsoleOutput(ConsoleOutput::VERBOSITY_NORMAL, null, $formatter);
    }

    /**
     * Creates a Composer instance
     *
     * @param  IOInterface                       $io             IO instance
     * @param  array<string, mixed>|string|null  $localConfig    either a configuration array or a filename to read from, if null it will
     *                                                           read from the default filename
     * @param  bool                              $disablePlugins Whether plugins should not be loaded
     * @param  bool                              $disableScripts Whether scripts should not be run
     * @param  string|null                       $cwd
     * @param  bool                              $fullLoad       Whether to initialize everything or only main project stuff (used when loading the global composer)
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException
     * @return Composer
     */
    public function createComposer(IOInterface $io, $localConfig = null, $disablePlugins = false, $cwd = null, $fullLoad = true, $disableScripts = false)
    {
        $cwd = $cwd ?: (string) getcwd();

        // load Composer configuration
        if (null === $localConfig) {
            $localConfig = static::getComposerFile();
        }

        $localConfigSource = Config::SOURCE_UNKNOWN;
        if (is_string($localConfig)) {
            $composerFile = $localConfig;

            $file = new JsonFile($localConfig, null, $io);

            if (!$file->exists()) {
                if ($localConfig === './composer.json' || $localConfig === 'composer.json') {
                    $message = 'Composer could not find a composer.json file in '.$cwd;
                } else {
                    $message = 'Composer could not find the config file: '.$localConfig;
                }
                $instructions = $fullLoad ? 'To initialize a project, please create a composer.json file. See https://getcomposer.org/basic-usage' : '';
                throw new \InvalidArgumentException($message.PHP_EOL.$instructions);
            }

            try {
                $file->validateSchema(JsonFile::LAX_SCHEMA);
            } catch (JsonValidationException $e) {
                $errors = ' - ' . implode(PHP_EOL . ' - ', $e->getErrors());
                $message = $e->getMessage() . ':' . PHP_EOL . $errors;
                throw new JsonValidationException($message);
            }
            $jsonParser = new JsonParser;
            try {
                $jsonParser->parse(file_get_contents($localConfig), JsonParser::DETECT_KEY_CONFLICTS);
            } catch (DuplicateKeyException $e) {
                $details = $e->getDetails();
                $io->writeError('<warning>Key '.$details['key'].' is a duplicate in '.$localConfig.' at line '.$details['line'].'</warning>');
            }

            $localConfig = $file->read();
            $localConfigSource = $file->getPath();
        }

        // Load config and override with local config/auth config
        $config = static::createConfig($io, $cwd);
        $config->merge($localConfig, $localConfigSource);
        if (isset($composerFile)) {
            $io->writeError('Loading config file ' . $composerFile .' ('.realpath($composerFile).')', true, IOInterface::DEBUG);
            $config->setConfigSource(new JsonConfigSource(new JsonFile(realpath($composerFile), null, $io)));

            $localAuthFile = new JsonFile(dirname(realpath($composerFile)) . '/auth.json', null, $io);
            if ($localAuthFile->exists()) {
                $io->writeError('Loading config file ' . $localAuthFile->getPath(), true, IOInterface::DEBUG);
                $config->merge(array('config' => $localAuthFile->read()), $localAuthFile->getPath());
                $config->setAuthConfigSource(new JsonConfigSource($localAuthFile, true));
            }
        }

        $vendorDir = $config->get('vendor-dir');

        // initialize composer
        $composer = new Composer();
        $composer->setConfig($config);

        if ($fullLoad) {
            // load auth configs into the IO instance
            $io->loadConfiguration($config);

            // load existing Composer\InstalledVersions instance if available
            if (!class_exists('Composer\InstalledVersions', false) && file_exists($installedVersionsPath = $config->get('vendor-dir').'/composer/InstalledVersions.php')) {
                include $installedVersionsPath;
            }
        }

        $httpDownloader = self::createHttpDownloader($io, $config);
        $process = new ProcessExecutor($io);
        $loop = new Loop($httpDownloader, $process);
        $composer->setLoop($loop);

        // initialize event dispatcher
        $dispatcher = new EventDispatcher($composer, $io, $process);
        $dispatcher->setRunScripts(!$disableScripts);
        $composer->setEventDispatcher($dispatcher);

        // initialize repository manager
        $rm = RepositoryFactory::manager($io, $config, $httpDownloader, $dispatcher, $process);
        $composer->setRepositoryManager($rm);

        // force-set the version of the global package if not defined as
        // guessing it adds no value and only takes time
        if (!$fullLoad && !isset($localConfig['version'])) {
            $localConfig['version'] = '1.0.0';
        }

        // load package
        $parser = new VersionParser;
        $guesser = new VersionGuesser($config, $process, $parser);
        $loader = $this->loadRootPackage($rm, $config, $parser, $guesser, $io);
        $package = $loader->load($localConfig, 'Composer\Package\RootPackage', $cwd);
        $composer->setPackage($package);

        // load local repository
        $this->addLocalRepository($io, $rm, $vendorDir, $package, $process);

        // initialize installation manager
        $im = $this->createInstallationManager($loop, $io, $dispatcher);
        $composer->setInstallationManager($im);

        if ($fullLoad) {
            // initialize download manager
            $dm = $this->createDownloadManager($io, $config, $httpDownloader, $process, $dispatcher);
            $composer->setDownloadManager($dm);

            // initialize autoload generator
            $generator = new AutoloadGenerator($dispatcher, $io);
            $composer->setAutoloadGenerator($generator);

            // initialize archive manager
            $am = $this->createArchiveManager($config, $dm, $loop);
            $composer->setArchiveManager($am);
        }

        // add installers to the manager (must happen after download manager is created since they read it out of $composer)
        $this->createDefaultInstallers($im, $composer, $io, $process);

        if ($fullLoad) {
            $globalComposer = null;
            if (realpath($config->get('home')) !== $cwd) {
                $globalComposer = $this->createGlobalComposer($io, $config, $disablePlugins, $disableScripts);
            }

            $pm = $this->createPluginManager($io, $composer, $globalComposer, $disablePlugins);
            $composer->setPluginManager($pm);

            $pm->loadInstalledPlugins();
        }

        // init locker if possible
        if ($fullLoad && isset($composerFile)) {
            $lockFile = self::getLockFile($composerFile);

            $locker = new Package\Locker($io, new JsonFile($lockFile, null, $io), $im, file_get_contents($composerFile), $process);
            $composer->setLocker($locker);
        }

        if ($fullLoad) {
            $initEvent = new Event(PluginEvents::INIT);
            $composer->getEventDispatcher()->dispatch($initEvent->getName(), $initEvent);

            // once everything is initialized we can
            // purge packages from local repos if they have been deleted on the filesystem
            $this->purgePackages($rm->getLocalRepository(), $im);
        }

        return $composer;
    }

    /**
     * @param  IOInterface   $io             IO instance
     * @param  bool          $disablePlugins Whether plugins should not be loaded
     * @param  bool          $disableScripts Whether scripts should not be executed
     * @return Composer|null
     */
    public static function createGlobal(IOInterface $io, $disablePlugins = false, $disableScripts = false)
    {
        $factory = new static();

        return $factory->createGlobalComposer($io, static::createConfig($io), $disablePlugins, $disableScripts, true);
    }

    /**
     * @param Repository\RepositoryManager $rm
     * @param string                       $vendorDir
     *
     * @return void
     */
    protected function addLocalRepository(IOInterface $io, RepositoryManager $rm, $vendorDir, RootPackageInterface $rootPackage, ProcessExecutor $process = null)
    {
        $fs = null;
        if ($process) {
            $fs = new Filesystem($process);
        }

        $rm->setLocalRepository(new Repository\InstalledFilesystemRepository(new JsonFile($vendorDir.'/composer/installed.json', null, $io), true, $rootPackage, $fs));
    }

    /**
     * @param bool $disablePlugins
     * @param bool $disableScripts
     * @param bool $fullLoad
     *
     * @return Composer|null
     */
    protected function createGlobalComposer(IOInterface $io, Config $config, $disablePlugins, $disableScripts, $fullLoad = false)
    {
        $composer = null;
        try {
            $composer = $this->createComposer($io, $config->get('home') . '/composer.json', $disablePlugins, $config->get('home'), $fullLoad, $disableScripts);
        } catch (\Exception $e) {
            $io->writeError('Failed to initialize global composer: '.$e->getMessage(), true, IOInterface::DEBUG);
        }

        return $composer;
    }

    /**
     * @param  IO\IOInterface             $io
     * @param  Config                     $config
     * @param  EventDispatcher            $eventDispatcher
     * @return Downloader\DownloadManager
     */
    public function createDownloadManager(IOInterface $io, Config $config, HttpDownloader $httpDownloader, ProcessExecutor $process, EventDispatcher $eventDispatcher = null)
    {
        $cache = null;
        if ($config->get('cache-files-ttl') > 0) {
            $cache = new Cache($io, $config->get('cache-files-dir'), 'a-z0-9_./');
            $cache->setReadOnly($config->get('cache-read-only'));
        }

        $fs = new Filesystem($process);

        $dm = new Downloader\DownloadManager($io, false, $fs);
        switch ($preferred = $config->get('preferred-install')) {
            case 'dist':
                $dm->setPreferDist(true);
                break;
            case 'source':
                $dm->setPreferSource(true);
                break;
            case 'auto':
            default:
                // noop
                break;
        }

        if (is_array($preferred)) {
            $dm->setPreferences($preferred);
        }

        $dm->setDownloader('git', new Downloader\GitDownloader($io, $config, $process, $fs));
        $dm->setDownloader('svn', new Downloader\SvnDownloader($io, $config, $process, $fs));
        $dm->setDownloader('fossil', new Downloader\FossilDownloader($io, $config, $process, $fs));
        $dm->setDownloader('hg', new Downloader\HgDownloader($io, $config, $process, $fs));
        $dm->setDownloader('perforce', new Downloader\PerforceDownloader($io, $config, $process, $fs));
        $dm->setDownloader('zip', new Downloader\ZipDownloader($io, $config, $httpDownloader, $eventDispatcher, $cache, $fs, $process));
        $dm->setDownloader('rar', new Downloader\RarDownloader($io, $config, $httpDownloader, $eventDispatcher, $cache, $fs, $process));
        $dm->setDownloader('tar', new Downloader\TarDownloader($io, $config, $httpDownloader, $eventDispatcher, $cache, $fs, $process));
        $dm->setDownloader('gzip', new Downloader\GzipDownloader($io, $config, $httpDownloader, $eventDispatcher, $cache, $fs, $process));
        $dm->setDownloader('xz', new Downloader\XzDownloader($io, $config, $httpDownloader, $eventDispatcher, $cache, $fs, $process));
        $dm->setDownloader('phar', new Downloader\PharDownloader($io, $config, $httpDownloader, $eventDispatcher, $cache, $fs, $process));
        $dm->setDownloader('file', new Downloader\FileDownloader($io, $config, $httpDownloader, $eventDispatcher, $cache, $fs, $process));
        $dm->setDownloader('path', new Downloader\PathDownloader($io, $config, $httpDownloader, $eventDispatcher, $cache, $fs, $process));

        return $dm;
    }

    /**
     * @param  Config                     $config The configuration
     * @param  Downloader\DownloadManager $dm     Manager use to download sources
     * @return Archiver\ArchiveManager
     */
    public function createArchiveManager(Config $config, Downloader\DownloadManager $dm, Loop $loop)
    {
        $am = new Archiver\ArchiveManager($dm, $loop);
        $am->addArchiver(new Archiver\ZipArchiver);
        $am->addArchiver(new Archiver\PharArchiver);

        return $am;
    }

    /**
     * @param  IOInterface          $io
     * @param  Composer             $composer
     * @param  Composer             $globalComposer
     * @param  bool                 $disablePlugins
     * @return Plugin\PluginManager
     */
    protected function createPluginManager(IOInterface $io, Composer $composer, Composer $globalComposer = null, $disablePlugins = false)
    {
        return new Plugin\PluginManager($io, $composer, $globalComposer, $disablePlugins);
    }

    /**
     * @return Installer\InstallationManager
     */
    public function createInstallationManager(Loop $loop, IOInterface $io, EventDispatcher $eventDispatcher = null)
    {
        return new Installer\InstallationManager($loop, $io, $eventDispatcher);
    }

    /**
     * @return void
     */
    protected function createDefaultInstallers(Installer\InstallationManager $im, Composer $composer, IOInterface $io, ProcessExecutor $process = null)
    {
        $fs = new Filesystem($process);
        $binaryInstaller = new Installer\BinaryInstaller($io, rtrim($composer->getConfig()->get('bin-dir'), '/'), $composer->getConfig()->get('bin-compat'), $fs, rtrim($composer->getConfig()->get('vendor-dir'), '/'));

        $im->addInstaller(new Installer\LibraryInstaller($io, $composer, null, $fs, $binaryInstaller));
        $im->addInstaller(new Installer\PluginInstaller($io, $composer, $fs, $binaryInstaller));
        $im->addInstaller(new Installer\MetapackageInstaller($io));
    }

    /**
     * @param InstalledRepositoryInterface   $repo repository to purge packages from
     * @param Installer\InstallationManager  $im   manager to check whether packages are still installed
     *
     * @return void
     */
    protected function purgePackages(InstalledRepositoryInterface $repo, Installer\InstallationManager $im)
    {
        foreach ($repo->getPackages() as $package) {
            if (!$im->isPackageInstalled($repo, $package)) {
                $repo->removePackage($package);
            }
        }
    }

    /**
     * @return Package\Loader\RootPackageLoader
     */
    protected function loadRootPackage(RepositoryManager $rm, Config $config, VersionParser $parser, VersionGuesser $guesser, IOInterface $io)
    {
        return new Package\Loader\RootPackageLoader($rm, $config, $parser, $guesser, $io);
    }

    /**
     * @param  IOInterface $io             IO instance
     * @param  mixed       $config         either a configuration array or a filename to read from, if null it will read from
     *                                     the default filename
     * @param  bool        $disablePlugins Whether plugins should not be loaded
     * @param  bool        $disableScripts Whether scripts should not be run
     * @return Composer
     */
    public static function create(IOInterface $io, $config = null, $disablePlugins = false, $disableScripts = false)
    {
        $factory = new static();

        return $factory->createComposer($io, $config, $disablePlugins, null, true, $disableScripts);
    }

    /**
     * If you are calling this in a plugin, you probably should instead use $composer->getLoop()->getHttpDownloader()
     *
     * @param  IOInterface    $io      IO instance
     * @param  Config         $config  Config instance
     * @param  mixed[]        $options Array of options passed directly to HttpDownloader constructor
     * @return HttpDownloader
     */
    public static function createHttpDownloader(IOInterface $io, Config $config, $options = array())
    {
        static $warned = false;
        $disableTls = false;
        // allow running the config command if disable-tls is in the arg list, even if openssl is missing, to allow disabling it via the config command
        if (isset($_SERVER['argv']) && in_array('disable-tls', $_SERVER['argv']) && (in_array('conf', $_SERVER['argv']) || in_array('config', $_SERVER['argv']))) {
            $warned = true;
            $disableTls = !extension_loaded('openssl');
        } elseif ($config->get('disable-tls') === true) {
            if (!$warned) {
                $io->writeError('<warning>You are running Composer with SSL/TLS protection disabled.</warning>');
            }
            $warned = true;
            $disableTls = true;
        } elseif (!extension_loaded('openssl')) {
            throw new Exception\NoSslException('The openssl extension is required for SSL/TLS protection but is not available. '
                . 'If you can not enable the openssl extension, you can disable this error, at your own risk, by setting the \'disable-tls\' option to true.');
        }
        $httpDownloaderOptions = array();
        if ($disableTls === false) {
            if ($config->get('cafile')) {
                $httpDownloaderOptions['ssl']['cafile'] = $config->get('cafile');
            }
            if ($config->get('capath')) {
                $httpDownloaderOptions['ssl']['capath'] = $config->get('capath');
            }
            $httpDownloaderOptions = array_replace_recursive($httpDownloaderOptions, $options);
        }
        try {
            $httpDownloader = new HttpDownloader($io, $config, $httpDownloaderOptions, $disableTls);
        } catch (TransportException $e) {
            if (false !== strpos($e->getMessage(), 'cafile')) {
                $io->write('<error>Unable to locate a valid CA certificate file. You must set a valid \'cafile\' option.</error>');
                $io->write('<error>A valid CA certificate file is required for SSL/TLS protection.</error>');
                if (PHP_VERSION_ID < 50600) {
                    $io->write('<error>It is recommended you upgrade to PHP 5.6+ which can detect your system CA file automatically.</error>');
                }
                $io->write('<error>You can disable this error, at your own risk, by setting the \'disable-tls\' option to true.</error>');
            }
            throw $e;
        }

        return $httpDownloader;
    }

    /**
     * @return bool
     */
    private static function useXdg()
    {
        foreach (array_keys($_SERVER) as $key) {
            if (strpos($key, 'XDG_') === 0) {
                return true;
            }
        }

        if (Silencer::call('is_dir', '/etc/xdg')) {
            return true;
        }

        return false;
    }

    /**
     * @throws \RuntimeException
     * @return string
     */
    private static function getUserDir()
    {
        $home = Platform::getEnv('HOME');
        if (!$home) {
            throw new \RuntimeException('The HOME or COMPOSER_HOME environment variable must be set for composer to run correctly');
        }

        return rtrim(strtr($home, '\\', '/'), '/');
    }
}
