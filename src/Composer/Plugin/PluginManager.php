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

namespace Composer\Plugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\InstallerInterface;
use Composer\IO\IOInterface;
use Composer\Package\BasePackage;
use Composer\Package\CompletePackage;
use Composer\Package\Locker;
use Composer\Package\Package;
use Composer\Package\Version\VersionParser;
use Composer\PartialComposer;
use Composer\Pcre\Preg;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\InstalledRepository;
use Composer\Repository\RootPackageRepository;
use Composer\Package\PackageInterface;
use Composer\Package\Link;
use Composer\Semver\Constraint\Constraint;
use Composer\Plugin\Capability\Capability;
use Composer\Util\PackageSorter;

/**
 * Plugin manager
 *
 * @author Nils Adermann <naderman@naderman.de>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PluginManager
{
    /** @var Composer */
    protected $composer;
    /** @var IOInterface */
    protected $io;
    /** @var PartialComposer|null */
    protected $globalComposer;
    /** @var VersionParser */
    protected $versionParser;
    /** @var bool */
    protected $disablePlugins = false;

    /** @var array<PluginInterface> */
    protected $plugins = array();
    /** @var array<string, PluginInterface|InstallerInterface> */
    protected $registeredPlugins = array();

    /**
     * @var array<non-empty-string, bool>|null
     */
    private $allowPluginRules;

    /**
     * @var array<non-empty-string, bool>|null
     */
    private $allowGlobalPluginRules;

    /** @var int */
    private static $classCounter = 0;

    public function __construct(IOInterface $io, Composer $composer, PartialComposer $globalComposer = null, bool $disablePlugins = false)
    {
        $this->io = $io;
        $this->composer = $composer;
        $this->globalComposer = $globalComposer;
        $this->versionParser = new VersionParser();
        $this->disablePlugins = $disablePlugins;
        $this->allowPluginRules = $this->parseAllowedPlugins($composer->getConfig()->get('allow-plugins'), $composer->getLocker());
        $this->allowGlobalPluginRules = $this->parseAllowedPlugins($globalComposer !== null ? $globalComposer->getConfig()->get('allow-plugins') : false);
    }

    /**
     * Loads all plugins from currently installed plugin packages
     *
     * @return void
     */
    public function loadInstalledPlugins(): void
    {
        if ($this->disablePlugins) {
            return;
        }

        $repo = $this->composer->getRepositoryManager()->getLocalRepository();
        $globalRepo = $this->globalComposer !== null ? $this->globalComposer->getRepositoryManager()->getLocalRepository() : null;
        $this->loadRepository($repo, false);
        if ($globalRepo) {
            $this->loadRepository($globalRepo, true);
        }
    }

    /**
     * Deactivate all plugins from currently installed plugin packages
     *
     * @return void
     */
    public function deactivateInstalledPlugins(): void
    {
        if ($this->disablePlugins) {
            return;
        }

        $repo = $this->composer->getRepositoryManager()->getLocalRepository();
        $this->deactivateRepository($repo, false);
        if ($this->globalComposer !== null) {
            $this->deactivateRepository($this->globalComposer->getRepositoryManager()->getLocalRepository(), true);
        }
    }

    /**
     * Gets all currently active plugin instances
     *
     * @return array<PluginInterface> plugins
     */
    public function getPlugins(): array
    {
        return $this->plugins;
    }

    /**
     * Gets global composer or null when main composer is not fully loaded
     */
    public function getGlobalComposer(): ?PartialComposer
    {
        return $this->globalComposer;
    }

    /**
     * Register a plugin package, activate it etc.
     *
     * If it's of type composer-installer it is registered as an installer
     * instead for BC
     *
     * @param PackageInterface $package
     * @param bool             $failOnMissingClasses By default this silently skips plugins that can not be found, but if set to true it fails with an exception
     * @param bool             $isGlobalPlugin       Set to true to denote plugins which are installed in the global Composer directory
     *
     * @return void
     *
     * @throws \UnexpectedValueException
     */
    public function registerPackage(PackageInterface $package, bool $failOnMissingClasses = false, bool $isGlobalPlugin = false): void
    {
        if ($this->disablePlugins) {
            return;
        }

        if (!$this->isPluginAllowed($package->getName(), $isGlobalPlugin)) {
            $this->io->writeError('Skipped loading "'.$package->getName() . '" '.($isGlobalPlugin ? '(installed globally) ' : '').'as it is not in config.allow-plugins', true, IOInterface::DEBUG);

            return;
        }

        if ($package->getType() === 'composer-plugin') {
            $requiresComposer = null;
            foreach ($package->getRequires() as $link) { /** @var Link $link */
                if ('composer-plugin-api' === $link->getTarget()) {
                    $requiresComposer = $link->getConstraint();
                    break;
                }
            }

            if (!$requiresComposer) {
                throw new \RuntimeException("Plugin ".$package->getName()." is missing a require statement for a version of the composer-plugin-api package.");
            }

            $currentPluginApiVersion = $this->getPluginApiVersion();
            $currentPluginApiConstraint = new Constraint('==', $this->versionParser->normalize($currentPluginApiVersion));

            if ($requiresComposer->getPrettyString() === $this->getPluginApiVersion()) {
                $this->io->writeError('<warning>The "' . $package->getName() . '" plugin requires composer-plugin-api '.$this->getPluginApiVersion().', this *WILL* break in the future and it should be fixed ASAP (require ^'.$this->getPluginApiVersion().' instead for example).</warning>');
            } elseif (!$requiresComposer->matches($currentPluginApiConstraint)) {
                $this->io->writeError('<warning>The "' . $package->getName() . '" plugin '.($isGlobalPlugin ? '(installed globally) ' : '').'was skipped because it requires a Plugin API version ("' . $requiresComposer->getPrettyString() . '") that does not match your Composer installation ("' . $currentPluginApiVersion . '"). You may need to run composer update with the "--no-plugins" option.</warning>');

                return;
            }

            if ($package->getName() === 'symfony/flex' && Preg::isMatch('{^[0-9.]+$}', $package->getVersion()) && version_compare($package->getVersion(), '1.9.8', '<')) {
                $this->io->writeError('<warning>The "' . $package->getName() . '" plugin '.($isGlobalPlugin ? '(installed globally) ' : '').'was skipped because it is not compatible with Composer 2+. Make sure to update it to version 1.9.8 or greater.</warning>');

                return;
            }
        }

        $oldInstallerPlugin = ($package->getType() === 'composer-installer');

        if (isset($this->registeredPlugins[$package->getName()])) {
            return;
        }

        $extra = $package->getExtra();
        if (empty($extra['class'])) {
            throw new \UnexpectedValueException('Error while installing '.$package->getPrettyName().', composer-plugin packages should have a class defined in their extra key to be usable.');
        }
        $classes = is_array($extra['class']) ? $extra['class'] : array($extra['class']);

        $localRepo = $this->composer->getRepositoryManager()->getLocalRepository();
        $globalRepo = $this->globalComposer !== null ? $this->globalComposer->getRepositoryManager()->getLocalRepository() : null;

        $rootPackage = clone $this->composer->getPackage();

        // clear files autoload rules from the root package as the root dependencies are not
        // necessarily all present yet when booting this runtime autoloader
        $rootPackageAutoloads = $rootPackage->getAutoload();
        $rootPackageAutoloads['files'] = array();
        $rootPackage->setAutoload($rootPackageAutoloads);
        $rootPackageAutoloads = $rootPackage->getDevAutoload();
        $rootPackageAutoloads['files'] = array();
        $rootPackage->setDevAutoload($rootPackageAutoloads);
        unset($rootPackageAutoloads);

        $rootPackageRepo = new RootPackageRepository($rootPackage);
        $installedRepo = new InstalledRepository(array($localRepo, $rootPackageRepo));
        if ($globalRepo) {
            $installedRepo->addRepository($globalRepo);
        }

        $autoloadPackages = array($package->getName() => $package);
        $autoloadPackages = $this->collectDependencies($installedRepo, $autoloadPackages, $package);

        $generator = $this->composer->getAutoloadGenerator();
        $autoloads = array(array($rootPackage, ''));
        foreach ($autoloadPackages as $autoloadPackage) {
            if ($autoloadPackage === $rootPackage) {
                continue;
            }

            $downloadPath = $this->getInstallPath($autoloadPackage, $globalRepo && $globalRepo->hasPackage($autoloadPackage));
            $autoloads[] = array($autoloadPackage, $downloadPath);
        }

        $map = $generator->parseAutoloads($autoloads, $rootPackage);
        $classLoader = $generator->createLoader($map, $this->composer->getConfig()->get('vendor-dir'));
        $classLoader->register(false);

        foreach ($map['files'] as $fileIdentifier => $file) {
            // exclude laminas/laminas-zendframework-bridge:src/autoload.php as it breaks Composer in some conditions
            // see https://github.com/composer/composer/issues/10349 and https://github.com/composer/composer/issues/10401
            // this hack can be removed once this deprecated package stop being installed
            if ($fileIdentifier === '7e9bd612cc444b3eed788ebbe46263a0') {
                continue;
            }
            \Composer\Autoload\composerRequire($fileIdentifier, $file);
        }

        foreach ($classes as $class) {
            if (class_exists($class, false)) {
                $class = trim($class, '\\');
                $path = $classLoader->findFile($class);
                $code = file_get_contents($path);
                $separatorPos = strrpos($class, '\\');
                $className = $class;
                if ($separatorPos) {
                    $className = substr($class, $separatorPos + 1);
                }
                $code = Preg::replace('{^((?:final\s+)?(?:\s*))class\s+('.preg_quote($className).')}mi', '$1class $2_composer_tmp'.self::$classCounter, $code, 1);
                $code = strtr($code, array(
                    '__FILE__' => var_export($path, true),
                    '__DIR__' => var_export(dirname($path), true),
                    '__CLASS__' => var_export($class, true),
                ));
                $code = Preg::replace('/^\s*<\?(php)?/i', '', $code, 1);
                eval($code);
                $class .= '_composer_tmp'.self::$classCounter;
                self::$classCounter++;
            }

            if ($oldInstallerPlugin) {
                if (!is_a($class, 'Composer\Installer\InstallerInterface', true)) {
                    throw new \RuntimeException('Could not activate plugin "'.$package->getName().'" as "'.$class.'" does not implement Composer\Installer\InstallerInterface');
                }
                $this->io->writeError('<warning>Loading "'.$package->getName() . '" '.($isGlobalPlugin ? '(installed globally) ' : '').'which is a legacy composer-installer built for Composer 1.x, it is likely to cause issues as you are running Composer 2.x.</warning>');
                $installer = new $class($this->io, $this->composer);
                $this->composer->getInstallationManager()->addInstaller($installer);
                $this->registeredPlugins[$package->getName()] = $installer;
            } elseif (class_exists($class)) {
                if (!is_a($class, 'Composer\Plugin\PluginInterface', true)) {
                    throw new \RuntimeException('Could not activate plugin "'.$package->getName().'" as "'.$class.'" does not implement Composer\Plugin\PluginInterface');
                }
                $plugin = new $class();
                $this->addPlugin($plugin, $isGlobalPlugin, $package);
                $this->registeredPlugins[$package->getName()] = $plugin;
            } elseif ($failOnMissingClasses) {
                throw new \UnexpectedValueException('Plugin '.$package->getName().' could not be initialized, class not found: '.$class);
            }
        }
    }

    /**
     * Deactivates a plugin package
     *
     * If it's of type composer-installer it is unregistered from the installers
     * instead for BC
     *
     * @param PackageInterface $package
     *
     * @return void
     *
     * @throws \UnexpectedValueException
     */
    public function deactivatePackage(PackageInterface $package): void
    {
        if ($this->disablePlugins) {
            return;
        }

        if (!isset($this->registeredPlugins[$package->getName()])) {
            return;
        }

        $plugin = $this->registeredPlugins[$package->getName()];
        unset($this->registeredPlugins[$package->getName()]);
        if ($plugin instanceof InstallerInterface) {
            $this->composer->getInstallationManager()->removeInstaller($plugin);
        } else {
            $this->removePlugin($plugin);
        }
    }

    /**
     * Uninstall a plugin package
     *
     * If it's of type composer-installer it is unregistered from the installers
     * instead for BC
     *
     * @param PackageInterface $package
     *
     * @return void
     *
     * @throws \UnexpectedValueException
     */
    public function uninstallPackage(PackageInterface $package): void
    {
        if ($this->disablePlugins) {
            return;
        }

        if (!isset($this->registeredPlugins[$package->getName()])) {
            return;
        }

        $plugin = $this->registeredPlugins[$package->getName()];
        if ($plugin instanceof InstallerInterface) {
            $this->deactivatePackage($package);
        } else {
            unset($this->registeredPlugins[$package->getName()]);
            $this->removePlugin($plugin);
            $this->uninstallPlugin($plugin);
        }
    }

    /**
     * Returns the version of the internal composer-plugin-api package.
     *
     * @return string
     */
    protected function getPluginApiVersion(): string
    {
        return PluginInterface::PLUGIN_API_VERSION;
    }

    /**
     * Adds a plugin, activates it and registers it with the event dispatcher
     *
     * Ideally plugin packages should be registered via registerPackage, but if you use Composer
     * programmatically and want to register a plugin class directly this is a valid way
     * to do it.
     *
     * @param PluginInterface   $plugin        plugin instance
     * @param bool              $isGlobalPlugin
     * @param ?PackageInterface $sourcePackage Package from which the plugin comes from
     *
     * @return void
     */
    public function addPlugin(PluginInterface $plugin, bool $isGlobalPlugin = false, PackageInterface $sourcePackage = null): void
    {
        if ($sourcePackage === null) {
            trigger_error('Calling PluginManager::addPlugin without $sourcePackage is deprecated, if you are using this please get in touch with us to explain the use case', E_USER_DEPRECATED);
        } elseif (!$this->isPluginAllowed($sourcePackage->getName(), $isGlobalPlugin)) {
            $this->io->writeError('Skipped loading "'.get_class($plugin).' from '.$sourcePackage->getName() . '" '.($isGlobalPlugin ? '(installed globally) ' : '').' as it is not in config.allow-plugins', true, IOInterface::DEBUG);

            return;
        }

        $details = array();
        if ($sourcePackage) {
            $details[] = 'from '.$sourcePackage->getName();
        }
        if ($isGlobalPlugin) {
            $details[] = 'installed globally';
        }
        $this->io->writeError('Loading plugin '.get_class($plugin).($details ? ' ('.implode(', ', $details).')' : ''), true, IOInterface::DEBUG);
        $this->plugins[] = $plugin;
        $plugin->activate($this->composer, $this->io);

        if ($plugin instanceof EventSubscriberInterface) {
            $this->composer->getEventDispatcher()->addSubscriber($plugin);
        }
    }

    /**
     * Removes a plugin, deactivates it and removes any listener the plugin has set on the plugin instance
     *
     * Ideally plugin packages should be deactivated via deactivatePackage, but if you use Composer
     * programmatically and want to deregister a plugin class directly this is a valid way
     * to do it.
     *
     * @param PluginInterface $plugin plugin instance
     *
     * @return void
     */
    public function removePlugin(PluginInterface $plugin): void
    {
        $index = array_search($plugin, $this->plugins, true);
        if ($index === false) {
            return;
        }

        $this->io->writeError('Unloading plugin '.get_class($plugin), true, IOInterface::DEBUG);
        unset($this->plugins[$index]);
        $plugin->deactivate($this->composer, $this->io);

        $this->composer->getEventDispatcher()->removeListener($plugin);
    }

    /**
     * Notifies a plugin it is being uninstalled and should clean up
     *
     * Ideally plugin packages should be uninstalled via uninstallPackage, but if you use Composer
     * programmatically and want to deregister a plugin class directly this is a valid way
     * to do it.
     *
     * @param PluginInterface $plugin plugin instance
     *
     * @return void
     */
    public function uninstallPlugin(PluginInterface $plugin): void
    {
        $this->io->writeError('Uninstalling plugin '.get_class($plugin), true, IOInterface::DEBUG);
        $plugin->uninstall($this->composer, $this->io);
    }

    /**
     * Load all plugins and installers from a repository
     *
     * If a plugin requires another plugin, the required one will be loaded first
     *
     * Note that plugins in the specified repository that rely on events that
     * have fired prior to loading will be missed. This means you likely want to
     * call this method as early as possible.
     *
     * @param RepositoryInterface $repo Repository to scan for plugins to install
     * @param bool                $isGlobalRepo
     *
     * @return void
     *
     * @throws \RuntimeException
     */
    private function loadRepository(RepositoryInterface $repo, bool $isGlobalRepo): void
    {
        $packages = $repo->getPackages();

        $weights = array();
        foreach ($packages as $package) {
            if ($package->getType() === 'composer-plugin') {
                $extra = $package->getExtra();
                if ($package->getName() === 'composer/installers' || true === ($extra['plugin-modifies-install-path'] ?? false)) {
                    $weights[$package->getName()] = -10000;
                }
            }
        }

        $sortedPackages = PackageSorter::sortPackages($packages, $weights);
        foreach ($sortedPackages as $package) {
            if (!($package instanceof CompletePackage)) {
                continue;
            }
            if ('composer-plugin' === $package->getType()) {
                $this->registerPackage($package, false, $isGlobalRepo);
            // Backward compatibility
            } elseif ('composer-installer' === $package->getType()) {
                $this->registerPackage($package, false, $isGlobalRepo);
            }
        }
    }

    /**
     * Deactivate all plugins and installers from a repository
     *
     * If a plugin requires another plugin, the required one will be deactivated last
     *
     * @param RepositoryInterface $repo Repository to scan for plugins to install
     * @param bool                $isGlobalRepo
     *
     * @return void
     */
    private function deactivateRepository(RepositoryInterface $repo, bool $isGlobalRepo): void
    {
        $packages = $repo->getPackages();
        $sortedPackages = array_reverse(PackageSorter::sortPackages($packages));

        foreach ($sortedPackages as $package) {
            if (!($package instanceof CompletePackage)) {
                continue;
            }
            if ('composer-plugin' === $package->getType()) {
                $this->deactivatePackage($package);
            // Backward compatibility
            } elseif ('composer-installer' === $package->getType()) {
                $this->deactivatePackage($package);
            }
        }
    }

    /**
     * Recursively generates a map of package names to packages for all deps
     *
     * @param InstalledRepository             $installedRepo Set of local repos
     * @param array<string, PackageInterface> $collected     Current state of the map for recursion
     * @param PackageInterface                $package       The package to analyze
     *
     * @return array<string, PackageInterface> Map of package names to packages
     */
    private function collectDependencies(InstalledRepository $installedRepo, array $collected, PackageInterface $package): array
    {
        foreach ($package->getRequires() as $requireLink) {
            foreach ($installedRepo->findPackagesWithReplacersAndProviders($requireLink->getTarget()) as $requiredPackage) {
                if (!isset($collected[$requiredPackage->getName()])) {
                    $collected[$requiredPackage->getName()] = $requiredPackage;
                    $collected = $this->collectDependencies($installedRepo, $collected, $requiredPackage);
                }
            }
        }

        return $collected;
    }

    /**
     * Retrieves the path a package is installed to.
     *
     * @param PackageInterface $package
     * @param bool             $global  Whether this is a global package
     *
     * @return string Install path
     */
    private function getInstallPath(PackageInterface $package, bool $global = false): string
    {
        if (!$global) {
            return $this->composer->getInstallationManager()->getInstallPath($package);
        }

        assert(null !== $this->globalComposer);

        return $this->globalComposer->getInstallationManager()->getInstallPath($package);
    }

    /**
     * @param  PluginInterface   $plugin
     * @param  string            $capability
     * @throws \RuntimeException On empty or non-string implementation class name value
     * @return null|string       The fully qualified class of the implementation or null if Plugin is not of Capable type or does not provide it
     */
    protected function getCapabilityImplementationClassName(PluginInterface $plugin, string $capability): ?string
    {
        if (!($plugin instanceof Capable)) {
            return null;
        }

        $capabilities = (array) $plugin->getCapabilities();

        if (!empty($capabilities[$capability]) && is_string($capabilities[$capability]) && trim($capabilities[$capability])) {
            return trim($capabilities[$capability]);
        }

        if (
            array_key_exists($capability, $capabilities)
            && (empty($capabilities[$capability]) || !is_string($capabilities[$capability]) || !trim($capabilities[$capability]))
        ) {
            throw new \UnexpectedValueException('Plugin '.get_class($plugin).' provided invalid capability class name(s), got '.var_export($capabilities[$capability], true));
        }

        return null;
    }

    /**
     * @template CapabilityClass of Capability
     * @param  PluginInterface               $plugin
     * @param  class-string<CapabilityClass> $capabilityClassName The fully qualified name of the API interface which the plugin may provide
     *                                                            an implementation of.
     * @param  array<mixed>                  $ctorArgs            Arguments passed to Capability's constructor.
     *                                                            Keeping it an array will allow future values to be passed w\o changing the signature.
     * @return null|Capability
     * @phpstan-param class-string<CapabilityClass> $capabilityClassName
     * @phpstan-return null|CapabilityClass
     */
    public function getPluginCapability(PluginInterface $plugin, $capabilityClassName, array $ctorArgs = array()): ?Capability
    {
        if ($capabilityClass = $this->getCapabilityImplementationClassName($plugin, $capabilityClassName)) {
            if (!class_exists($capabilityClass)) {
                throw new \RuntimeException("Cannot instantiate Capability, as class $capabilityClass from plugin ".get_class($plugin)." does not exist.");
            }

            $ctorArgs['plugin'] = $plugin;
            $capabilityObj = new $capabilityClass($ctorArgs);

            // FIXME these could use is_a and do the check *before* instantiating once drop support for php<5.3.9
            if (!$capabilityObj instanceof Capability || !$capabilityObj instanceof $capabilityClassName) {
                throw new \RuntimeException(
                    'Class ' . $capabilityClass . ' must implement both Composer\Plugin\Capability\Capability and '. $capabilityClassName . '.'
                );
            }

            return $capabilityObj;
        }

        return null;
    }

    /**
     * @template CapabilityClass of Capability
     * @param  class-string<CapabilityClass> $capabilityClassName The fully qualified name of the API interface which the plugin may provide
     *                                                            an implementation of.
     * @param  array<mixed>                  $ctorArgs            Arguments passed to Capability's constructor.
     *                                                            Keeping it an array will allow future values to be passed w\o changing the signature.
     * @return CapabilityClass[]
     */
    public function getPluginCapabilities($capabilityClassName, array $ctorArgs = array()): array
    {
        $capabilities = array();
        foreach ($this->getPlugins() as $plugin) {
            $capability = $this->getPluginCapability($plugin, $capabilityClassName, $ctorArgs);
            if (null !== $capability) {
                $capabilities[] = $capability;
            }
        }

        return $capabilities;
    }

    /**
     * @param  array<string, bool>|bool $allowPluginsConfig
     * @return array<non-empty-string, bool>|null
     */
    private function parseAllowedPlugins($allowPluginsConfig, ?Locker $locker = null): ?array
    {
        if (array() === $allowPluginsConfig && $locker !== null && $locker->isLocked() && version_compare($locker->getPluginApi(), '2.2.0', '<')) {
            return null;
        }

        if (true === $allowPluginsConfig) {
            return array('{}' => true);
        }

        if (false === $allowPluginsConfig) {
            return array('{}' => false);
        }

        $rules = array();
        foreach ($allowPluginsConfig as $pattern => $allow) {
            $rules[BasePackage::packageNameToRegexp($pattern)] = $allow;
        }

        return $rules;
    }

    /**
     * @internal
     *
     * @param string $package
     * @param bool $isGlobalPlugin
     * @return bool
     */
    public function isPluginAllowed(string $package, bool $isGlobalPlugin): bool
    {
        if ($isGlobalPlugin) {
            $rules = &$this->allowGlobalPluginRules;
        } else {
            $rules = &$this->allowPluginRules;
        }

        // This is a BC mode for lock files created pre-Composer-2.2 where the expectation of
        // an allow-plugins config being present cannot be made.
        if ($rules === null) {
            if (!$this->io->isInteractive()) {
                $this->io->writeError('<warning>For additional security you should declare the allow-plugins config with a list of packages names that are allowed to run code. See https://getcomposer.org/allow-plugins</warning>');
                $this->io->writeError('<warning>This warning will become an exception once you run composer update!</warning>');

                $rules = array('{}' => true);

                // if no config is defined we allow all plugins for BC
                return true;
            }

            // keep going and prompt the user
            $rules = array();
        }

        foreach ($rules as $pattern => $allow) {
            if (Preg::isMatch($pattern, $package)) {
                return $allow === true;
            }
        }

        if ($package === 'composer/package-versions-deprecated') {
            return false;
        }

        if ($this->io->isInteractive()) {
            $composer = $isGlobalPlugin && $this->globalComposer !== null ? $this->globalComposer : $this->composer;

            $this->io->writeError('<warning>'.$package.($isGlobalPlugin ? ' (installed globally)' : '').' contains a Composer plugin which is currently not in your allow-plugins config. See https://getcomposer.org/allow-plugins</warning>');
            $attempts = 0;
            while (true) {
                // do not allow more than 5 prints of the help message, at some point assume the
                // input is not interactive and bail defaulting to a disabled plugin
                $default = '?';
                if ($attempts > 5) {
                    $this->io->writeError('Too many failed prompts, aborting.');
                    break;
                }

                switch ($answer = $this->io->ask('Do you trust "<fg=green;options=bold>'.$package.'</>" to execute code and wish to enable it now? (writes "allow-plugins" to composer.json) [<comment>y,n,d,?</comment>] ', $default)) {
                    case 'y':
                    case 'n':
                    case 'd':
                        $allow = $answer === 'y';

                        // persist answer in current rules to avoid prompting again if the package gets reloaded
                        $rules[BasePackage::packageNameToRegexp($package)] = $allow;

                        // persist answer in composer.json if it wasn't simply discarded
                        if ($answer === 'y' || $answer === 'n') {
                            $composer->getConfig()->getConfigSource()->addConfigSetting('allow-plugins.'.$package, $allow);
                        }

                        return $allow;

                    case '?':
                    default:
                        $attempts++;
                        $this->io->writeError(array(
                            'y - add package to allow-plugins in composer.json and let it run immediately',
                            'n - add package (as disallowed) to allow-plugins in composer.json to suppress further prompts',
                            'd - discard this, do not change composer.json and do not allow the plugin to run',
                            '? - print help',
                        ));
                        break;
                }
            }
        }

        throw new \UnexpectedValueException(
            $package.($isGlobalPlugin ? ' (installed globally)' : '').' contains a Composer plugin which is blocked by your allow-plugins config. You may add it to the list if you consider it safe.'.PHP_EOL.
            'You can run "composer '.($isGlobalPlugin ? 'global ' : '').'config --no-plugins allow-plugins.'.$package.' [true|false]" to enable it (true) or disable it explicitly and suppress this exception (false)'.PHP_EOL.
            'See https://getcomposer.org/allow-plugins'
        );
    }
}
