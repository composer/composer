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

use Composer\Config\JsonConfigSource;
use Composer\Json\JsonFile;
use Composer\IO\IOInterface;
use Composer\Package\Archiver;
use Composer\Package\Version\VersionGuesser;
use Composer\Package\RootPackageInterface;
use Composer\Repository\FilesystemRepository;
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
use Phar;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Output\ConsoleOutput;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Autoload\AutoloadGenerator;
use Composer\Package\Version\VersionParser;
use Composer\Downloader\TransportException;
use Composer\Json\JsonValidationException;
use Composer\Repository\InstalledRepositoryInterface;
use UnexpectedValueException;
use ZipArchive;

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
     */
    protected static function getHomeDir(): string
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
        $dirs = [];

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

    protected static function getCacheDir(string $home): string
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

    protected static function getDataDir(string $home): string
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

    public static function createConfig(?IOInterface $io = null, ?string $cwd = null): Config
    {
        $cwd = $cwd ?? Platform::getCwd(true);

        $config = new Config(true, $cwd);

        // determine and add main dirs to the config
        $home = self::getHomeDir();
        $config->merge([
            'config' => [
                'home' => $home,
                'cache-dir' => self::getCacheDir($home),
                'data-dir' => self::getDataDir($home),
            ],
        ], Config::SOURCE_DEFAULT);

        // load global config
        $file = new JsonFile($config->get('home').'/config.json');
        if ($file->exists()) {
            if ($io instanceof IOInterface) {
                $io->writeError('Loading config file ' . $file->getPath(), true, IOInterface::DEBUG);
            }
            self::validateJsonSchema($io, $file);
            $config->merge($file->read(), $file->getPath());
        }
        $config->setConfigSource(new JsonConfigSource($file));

        $htaccessProtect = $config->get('htaccess-protect');
        if ($htaccessProtect) {
            // Protect directory against web access. Since HOME could be
            // the www-data's user home and be web-accessible it is a
            // potential security risk
            $dirs = [$config->get('home'), $config->get('cache-dir'), $config->get('data-dir')];
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
            if ($io instanceof IOInterface) {
                $io->writeError('Loading config file ' . $file->getPath(), true, IOInterface::DEBUG);
            }
            self::validateJsonSchema($io, $file, JsonFile::AUTH_SCHEMA);
            $config->merge(['config' => $file->read()], $file->getPath());
        }
        $config->setAuthConfigSource(new JsonConfigSource($file, true));

        self::loadComposerAuthEnv($config, $io);

        return $config;
    }

    public static function getComposerFile(): string
    {
        $env = Platform::getEnv('COMPOSER');
        if (is_string($env)) {
            $env = trim($env);
            if ('' !== $env) {
                if (is_dir($env)) {
                    throw new \RuntimeException('The COMPOSER environment variable is set to '.$env.' which is a directory, this variable should point to a composer.json or be left unset.');
                }

                return $env;
            }
        }

        return './composer.json';
    }

    public static function getLockFile(string $composerFile): string
    {
        return "json" === pathinfo($composerFile, PATHINFO_EXTENSION)
                ? substr($composerFile, 0, -4).'lock'
                : $composerFile . '.lock';
    }

    /**
     * @return array{highlight: OutputFormatterStyle, warning: OutputFormatterStyle}
     */
    public static function createAdditionalStyles(): array
    {
        return [
            'highlight' => new OutputFormatterStyle('red'),
            'warning' => new OutputFormatterStyle('black', 'yellow'),
        ];
    }

    public static function createOutput(): ConsoleOutput
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
     * @param  bool|'local'|'global'             $disablePlugins Whether plugins should not be loaded, can be set to local or global to only disable local/global plugins
     * @param  bool                              $disableScripts Whether scripts should not be run
     * @param  bool                              $fullLoad       Whether to initialize everything or only main project stuff (used when loading the global composer)
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException
     * @return Composer|PartialComposer Composer if $fullLoad is true, otherwise PartialComposer
     * @phpstan-return ($fullLoad is true ? Composer : PartialComposer)
     */
    public function createComposer(IOInterface $io, $localConfig = null, $disablePlugins = false, ?string $cwd = null, bool $fullLoad = true, bool $disableScripts = false)
    {
        // if a custom composer.json path is given, we change the default cwd to be that file's directory
        if (is_string($localConfig) && is_file($localConfig) && null === $cwd) {
            $cwd = dirname($localConfig);
        }

        $cwd = $cwd ?? Platform::getCwd(true);

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

            if (!Platform::isInputCompletionProcess()) {
                try {
                    $file->validateSchema(JsonFile::LAX_SCHEMA);
                } catch (JsonValidationException $e) {
                    $errors = ' - ' . implode(PHP_EOL . ' - ', $e->getErrors());
                    $message = $e->getMessage() . ':' . PHP_EOL . $errors;
                    throw new JsonValidationException($message);
                }
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
                self::validateJsonSchema($io, $localAuthFile, JsonFile::AUTH_SCHEMA);
                $config->merge(['config' => $localAuthFile->read()], $localAuthFile->getPath());
                $config->setLocalAuthConfigSource(new JsonConfigSource($localAuthFile, true));
            }
        }

        // make sure we load the auth env again over the local auth.json + composer.json config
        self::loadComposerAuthEnv($config, $io);

        $vendorDir = $config->get('vendor-dir');

        // initialize composer
        $composer = $fullLoad ? new Composer() : new PartialComposer();
        $composer->setConfig($config);

        if ($fullLoad) {
            // load auth configs into the IO instance
            $io->loadConfiguration($config);

            // load existing Composer\InstalledVersions instance if available and scripts/plugins are allowed, as they might need it
            // we only load if the InstalledVersions class wasn't defined yet so that this is only loaded once
            if (false === $disablePlugins && false === $disableScripts && !class_exists('Composer\InstalledVersions', false) && file_exists($installedVersionsPath = $config->get('vendor-dir').'/composer/installed.php')) {
                // force loading the class at this point so it is loaded from the composer phar and not from the vendor dir
                // as we cannot guarantee integrity of that file
                if (class_exists('Composer\InstalledVersions')) {
                    FilesystemRepository::safelyLoadInstalledVersions($installedVersionsPath);
                }
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

        if ($composer instanceof Composer) {
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

        // init locker if possible
        if ($composer instanceof Composer && isset($composerFile)) {
            $lockFile = self::getLockFile($composerFile);
            if (!$config->get('lock') && file_exists($lockFile)) {
                $io->writeError('<warning>'.$lockFile.' is present but ignored as the "lock" config option is disabled.</warning>');
            }

            $locker = new Package\Locker($io, new JsonFile($config->get('lock') ? $lockFile : Platform::getDevNull(), null, $io), $im, file_get_contents($composerFile), $process);
            $composer->setLocker($locker);
        } elseif ($composer instanceof Composer) {
            $locker = new Package\Locker($io, new JsonFile(Platform::getDevNull(), null, $io), $im, JsonFile::encode($localConfig), $process);
            $composer->setLocker($locker);
        }

        if ($composer instanceof Composer) {
            $globalComposer = null;
            if (realpath($config->get('home')) !== $cwd) {
                $globalComposer = $this->createGlobalComposer($io, $config, $disablePlugins, $disableScripts);
            }

            $pm = $this->createPluginManager($io, $composer, $globalComposer, $disablePlugins);
            $composer->setPluginManager($pm);

            if (realpath($config->get('home')) === $cwd) {
                $pm->setRunningInGlobalDir(true);
            }

            $pm->loadInstalledPlugins();
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
     * @param  bool          $disablePlugins Whether plugins should not be loaded
     * @param  bool          $disableScripts Whether scripts should not be executed
     */
    public static function createGlobal(IOInterface $io, bool $disablePlugins = false, bool $disableScripts = false): ?Composer
    {
        $factory = new static();

        return $factory->createGlobalComposer($io, static::createConfig($io), $disablePlugins, $disableScripts, true);
    }

    /**
     * @param Repository\RepositoryManager $rm
     */
    protected function addLocalRepository(IOInterface $io, RepositoryManager $rm, string $vendorDir, RootPackageInterface $rootPackage, ?ProcessExecutor $process = null): void
    {
        $fs = null;
        if ($process) {
            $fs = new Filesystem($process);
        }

        $rm->setLocalRepository(new Repository\InstalledFilesystemRepository(new JsonFile($vendorDir.'/composer/installed.json', null, $io), true, $rootPackage, $fs));
    }

    /**
     * @param bool|'local'|'global' $disablePlugins Whether plugins should not be loaded, can be set to local or global to only disable local/global plugins
     * @return PartialComposer|Composer|null By default PartialComposer, but Composer if $fullLoad is set to true
     * @phpstan-return ($fullLoad is true ? Composer|null : PartialComposer|null)
     */
    protected function createGlobalComposer(IOInterface $io, Config $config, $disablePlugins, bool $disableScripts, bool $fullLoad = false): ?PartialComposer
    {
        // make sure if disable plugins was 'local' it is now turned off
        $disablePlugins = $disablePlugins === 'global' || $disablePlugins === true;

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
     * @param  EventDispatcher            $eventDispatcher
     */
    public function createDownloadManager(IOInterface $io, Config $config, HttpDownloader $httpDownloader, ProcessExecutor $process, ?EventDispatcher $eventDispatcher = null): Downloader\DownloadManager
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
        if (class_exists(ZipArchive::class)) {
            $am->addArchiver(new Archiver\ZipArchiver);
        }
        if (class_exists(Phar::class)) {
            $am->addArchiver(new Archiver\PharArchiver);
        }

        return $am;
    }

    /**
     * @param  bool|'local'|'global' $disablePlugins Whether plugins should not be loaded, can be set to local or global to only disable local/global plugins
     */
    protected function createPluginManager(IOInterface $io, Composer $composer, ?PartialComposer $globalComposer = null, $disablePlugins = false): Plugin\PluginManager
    {
        return new Plugin\PluginManager($io, $composer, $globalComposer, $disablePlugins);
    }

    public function createInstallationManager(Loop $loop, IOInterface $io, ?EventDispatcher $eventDispatcher = null): Installer\InstallationManager
    {
        return new Installer\InstallationManager($loop, $io, $eventDispatcher);
    }

    protected function createDefaultInstallers(Installer\InstallationManager $im, PartialComposer $composer, IOInterface $io, ?ProcessExecutor $process = null): void
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
     */
    protected function purgePackages(InstalledRepositoryInterface $repo, Installer\InstallationManager $im): void
    {
        foreach ($repo->getPackages() as $package) {
            if (!$im->isPackageInstalled($repo, $package)) {
                $repo->removePackage($package);
            }
        }
    }

    protected function loadRootPackage(RepositoryManager $rm, Config $config, VersionParser $parser, VersionGuesser $guesser, IOInterface $io): Package\Loader\RootPackageLoader
    {
        return new Package\Loader\RootPackageLoader($rm, $config, $parser, $guesser, $io);
    }

    /**
     * @param  IOInterface $io             IO instance
     * @param  mixed       $config         either a configuration array or a filename to read from, if null it will read from
     *                                     the default filename
     * @param  bool|'local'|'global' $disablePlugins Whether plugins should not be loaded, can be set to local or global to only disable local/global plugins
     * @param  bool        $disableScripts Whether scripts should not be run
     */
    public static function create(IOInterface $io, $config = null, $disablePlugins = false, bool $disableScripts = false): Composer
    {
        $factory = new static();

        // for BC reasons, if a config is passed in either as array or a path that is not the default composer.json path
        // we disable local plugins as they really should not be loaded from CWD
        // If you want to avoid this behavior, you should be calling createComposer directly with a $cwd arg set correctly
        // to the path where the composer.json being loaded resides
        if ($config !== null && $config !== self::getComposerFile() && $disablePlugins === false) {
            $disablePlugins = 'local';
        }

        return $factory->createComposer($io, $config, $disablePlugins, null, true, $disableScripts);
    }

    /**
     * If you are calling this in a plugin, you probably should instead use $composer->getLoop()->getHttpDownloader()
     *
     * @param  IOInterface    $io      IO instance
     * @param  Config         $config  Config instance
     * @param  mixed[]        $options Array of options passed directly to HttpDownloader constructor
     */
    public static function createHttpDownloader(IOInterface $io, Config $config, array $options = []): HttpDownloader
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
        $httpDownloaderOptions = [];
        if ($disableTls === false) {
            if ('' !== $config->get('cafile')) {
                $httpDownloaderOptions['ssl']['cafile'] = $config->get('cafile');
            }
            if ('' !== $config->get('capath')) {
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
                $io->write('<error>You can disable this error, at your own risk, by setting the \'disable-tls\' option to true.</error>');
            }
            throw $e;
        }

        return $httpDownloader;
    }

    private static function loadComposerAuthEnv(Config $config, ?IOInterface $io): void
    {
        $composerAuthEnv = Platform::getEnv('COMPOSER_AUTH');
        if (false === $composerAuthEnv || '' === $composerAuthEnv) {
            return;
        }

        $authData = json_decode($composerAuthEnv);
        if (null === $authData) {
            throw new \UnexpectedValueException('COMPOSER_AUTH environment variable is malformed, should be a valid JSON object');
        }

        if ($io instanceof IOInterface) {
            $io->writeError('Loading auth config from COMPOSER_AUTH', true, IOInterface::DEBUG);
        }
        self::validateJsonSchema($io, $authData, JsonFile::AUTH_SCHEMA, 'COMPOSER_AUTH');
        $authData = json_decode($composerAuthEnv, true);
        if (null !== $authData) {
            $config->merge(['config' => $authData], 'COMPOSER_AUTH');
        }
    }

    private static function useXdg(): bool
    {
        foreach (array_keys($_SERVER) as $key) {
            if (strpos((string) $key, 'XDG_') === 0) {
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
     */
    private static function getUserDir(): string
    {
        $home = Platform::getEnv('HOME');
        if (!$home) {
            throw new \RuntimeException('The HOME or COMPOSER_HOME environment variable must be set for composer to run correctly');
        }

        return rtrim(strtr($home, '\\', '/'), '/');
    }

    /**
     * @param mixed $fileOrData
     * @param JsonFile::*_SCHEMA $schema
     */
    private static function validateJsonSchema(?IOInterface $io, $fileOrData, int $schema = JsonFile::LAX_SCHEMA, ?string $source = null): void
    {
        if (Platform::isInputCompletionProcess()) {
            return;
        }

        try {
            if ($fileOrData instanceof JsonFile) {
                $fileOrData->validateSchema($schema);
            } else {
                if (null === $source) {
                    throw new \InvalidArgumentException('$source is required to be provided if $fileOrData is arbitrary data');
                }
                JsonFile::validateJsonSchema($source, $fileOrData, $schema);
            }
        } catch (JsonValidationException $e) {
            $msg = $e->getMessage().', this may result in errors and should be resolved:'.PHP_EOL.' - '.implode(PHP_EOL.' - ', $e->getErrors());
            if ($io instanceof IOInterface) {
                $io->writeError('<warning>'.$msg.'</>');
            } else {
                throw new UnexpectedValueException($msg);
            }
        }
    }
}
