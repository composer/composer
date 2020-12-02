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

namespace Composer\Plugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\CompletePackage;
use Composer\Package\Package;
use Composer\Package\RootPackage;
use Composer\Package\Version\VersionParser;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\InstalledRepository;
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
    /** @var Composer */
    protected $globalComposer;
    /** @var VersionParser */
    protected $versionParser;
    /** @var bool */
    protected $disablePlugins = false;

    /** @var array<PluginInterface> */
    protected $plugins = array();
    /** @var array<string, PluginInterface> */
    protected $registeredPlugins = array();

    /** @var int */
    private static $classCounter = 0;

    /**
     * Initializes plugin manager
     *
     * @param IOInterface $io
     * @param Composer    $composer
     * @param Composer    $globalComposer
     * @param bool        $disablePlugins
     */
    public function __construct(IOInterface $io, Composer $composer, Composer $globalComposer = null, $disablePlugins = false)
    {
        $this->io = $io;
        $this->composer = $composer;
        $this->globalComposer = $globalComposer;
        $this->versionParser = new VersionParser();
        $this->disablePlugins = $disablePlugins;
    }

    /**
     * Loads all plugins from currently installed plugin packages
     */
    public function loadInstalledPlugins()
    {
        if ($this->disablePlugins) {
            return;
        }

        $repo = $this->composer->getRepositoryManager()->getLocalRepository();
        $globalRepo = $this->globalComposer ? $this->globalComposer->getRepositoryManager()->getLocalRepository() : null;
        if ($repo) {
            $this->loadRepository($repo, false);
        }
        if ($globalRepo) {
            $this->loadRepository($globalRepo, true);
        }
    }

    /**
     * Gets all currently active plugin instances
     *
     * @return array plugins
     */
    public function getPlugins()
    {
        return $this->plugins;
    }

    /**
     * Gets global composer or null when main composer is not fully loaded
     *
     * @return Composer|null
     */
    public function getGlobalComposer()
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
     * @throws \UnexpectedValueException
     */
    public function registerPackage(PackageInterface $package, $failOnMissingClasses = false, $isGlobalPlugin = false)
    {
        if ($this->disablePlugins) {
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

            if ($package->getName() === 'symfony/flex' && preg_match('{^[0-9.]+$}', $package->getVersion()) && version_compare($package->getVersion(), '1.9.8', '<')) {
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
        $globalRepo = $this->globalComposer ? $this->globalComposer->getRepositoryManager()->getLocalRepository() : null;

        $installedRepo = new InstalledRepository(array($localRepo));
        if ($globalRepo) {
            $installedRepo->addRepository($globalRepo);
        }

        $autoloadPackages = array($package->getName() => $package);
        $autoloadPackages = $this->collectDependencies($installedRepo, $autoloadPackages, $package);

        $generator = $this->composer->getAutoloadGenerator();
        $autoloads = array();
        foreach ($autoloadPackages as $autoloadPackage) {
            $downloadPath = $this->getInstallPath($autoloadPackage, $globalRepo && $globalRepo->hasPackage($autoloadPackage));
            $autoloads[] = array($autoloadPackage, $downloadPath);
        }

        $map = $generator->parseAutoloads($autoloads, new RootPackage('dummy/root-package', '1.0.0.0', '1.0.0'));
        $classLoader = $generator->createLoader($map);
        $classLoader->register();

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
                $code = preg_replace('{^((?:final\s+)?(?:\s*))class\s+('.preg_quote($className).')}mi', '$1class $2_composer_tmp'.self::$classCounter, $code, 1);
                $code = strtr($code, array(
                    '__FILE__' => var_export($path, true),
                    '__DIR__' => var_export(dirname($path), true),
                    '__CLASS__' => var_export($class, true),
                ));
                $code = preg_replace('/^\s*<\?(php)?/i', '', $code, 1);
                eval($code);
                $class .= '_composer_tmp'.self::$classCounter;
                self::$classCounter++;
            }

            if ($oldInstallerPlugin) {
                $installer = new $class($this->io, $this->composer);
                $this->composer->getInstallationManager()->addInstaller($installer);
                $this->registeredPlugins[$package->getName()] = $installer;
            } elseif (class_exists($class)) {
                $plugin = new $class();
                $this->addPlugin($plugin, $isGlobalPlugin);
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
     * @throws \UnexpectedValueException
     */
    public function deactivatePackage(PackageInterface $package)
    {
        if ($this->disablePlugins) {
            return;
        }

        $oldInstallerPlugin = ($package->getType() === 'composer-installer');

        if (!isset($this->registeredPlugins[$package->getName()])) {
            return;
        }

        if ($oldInstallerPlugin) {
            $installer = $this->registeredPlugins[$package->getName()];
            unset($this->registeredPlugins[$package->getName()]);
            $this->composer->getInstallationManager()->removeInstaller($installer);
        } else {
            $plugin = $this->registeredPlugins[$package->getName()];
            unset($this->registeredPlugins[$package->getName()]);
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
     * @throws \UnexpectedValueException
     */
    public function uninstallPackage(PackageInterface $package)
    {
        if ($this->disablePlugins) {
            return;
        }

        $oldInstallerPlugin = ($package->getType() === 'composer-installer');

        if (!isset($this->registeredPlugins[$package->getName()])) {
            return;
        }

        if ($oldInstallerPlugin) {
            $this->deactivatePackage($package);
        } else {
            $plugin = $this->registeredPlugins[$package->getName()];
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
    protected function getPluginApiVersion()
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
     * @param PluginInterface $plugin plugin instance
     */
    public function addPlugin(PluginInterface $plugin, $isGlobalPlugin = false)
    {
        $this->io->writeError('Loading plugin '.get_class($plugin).($isGlobalPlugin ? ' (installed globally)' : ''), true, IOInterface::DEBUG);
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
     */
    public function removePlugin(PluginInterface $plugin)
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
     */
    public function uninstallPlugin(PluginInterface $plugin)
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
     *
     * @throws \RuntimeException
     */
    private function loadRepository(RepositoryInterface $repo, $isGlobalRepo)
    {
        $packages = $repo->getPackages();
        $sortedPackages = PackageSorter::sortPackages($packages);
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
     * Recursively generates a map of package names to packages for all deps
     *
     * @param InstalledRepository $installedRepo Set of local repos
     * @param array               $collected     Current state of the map for recursion
     * @param PackageInterface    $package       The package to analyze
     *
     * @return array Map of package names to packages
     */
    private function collectDependencies(InstalledRepository $installedRepo, array $collected, PackageInterface $package)
    {
        $requires = array_merge(
            $package->getRequires(),
            $package->getDevRequires()
        );

        foreach ($requires as $requireLink) {
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
    private function getInstallPath(PackageInterface $package, $global = false)
    {
        if (!$global) {
            return $this->composer->getInstallationManager()->getInstallPath($package);
        }

        return $this->globalComposer->getInstallationManager()->getInstallPath($package);
    }

    /**
     * @param  PluginInterface   $plugin
     * @param  string            $capability
     * @throws \RuntimeException On empty or non-string implementation class name value
     * @return null|string       The fully qualified class of the implementation or null if Plugin is not of Capable type or does not provide it
     */
    protected function getCapabilityImplementationClassName(PluginInterface $plugin, $capability)
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
            throw new \UnexpectedValueException('Plugin '.get_class($plugin).' provided invalid capability class name(s), got '.var_export($capabilities[$capability], 1));
        }
    }

    /**
     * @param  PluginInterface $plugin
     * @param  string          $capabilityClassName The fully qualified name of the API interface which the plugin may provide
     *                                              an implementation of.
     * @param  array           $ctorArgs            Arguments passed to Capability's constructor.
     *                                              Keeping it an array will allow future values to be passed w\o changing the signature.
     * @return null|Capability
     */
    public function getPluginCapability(PluginInterface $plugin, $capabilityClassName, array $ctorArgs = array())
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
    }

    /**
     * @param  string       $capabilityClassName The fully qualified name of the API interface which the plugin may provide
     *                                           an implementation of.
     * @param  array        $ctorArgs            Arguments passed to Capability's constructor.
     *                                           Keeping it an array will allow future values to be passed w\o changing the signature.
     * @return Capability[]
     */
    public function getPluginCapabilities($capabilityClassName, array $ctorArgs = array())
    {
        $capabilities = array();
        foreach ($this->getPlugins() as $plugin) {
            if ($capability = $this->getPluginCapability($plugin, $capabilityClassName, $ctorArgs)) {
                $capabilities[] = $capability;
            }
        }

        return $capabilities;
    }
}
