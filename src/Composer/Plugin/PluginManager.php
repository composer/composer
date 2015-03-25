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
use Composer\Package\Package;
use Composer\Package\Version\VersionParser;
use Composer\Repository\RepositoryInterface;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Package\Link;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\DependencyResolver\Pool;

/**
 * Plugin manager
 *
 * @author Nils Adermann <naderman@naderman.de>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PluginManager
{
    protected $composer;
    protected $io;
    protected $globalComposer;
    protected $versionParser;

    protected $plugins = array();
    protected $registeredPlugins = array();

    private static $classCounter = 0;

    /**
     * Initializes plugin manager
     *
     * @param IOInterface         $io
     * @param Composer            $composer
     * @param Composer            $globalComposer
     */
    public function __construct(IOInterface $io, Composer $composer, Composer $globalComposer = null)
    {
        $this->io = $io;
        $this->composer = $composer;
        $this->globalComposer = $globalComposer;
        $this->versionParser = new VersionParser();
    }

    /**
     * Loads all plugins from currently installed plugin packages
     */
    public function loadInstalledPlugins()
    {
        $repo = $this->composer->getRepositoryManager()->getLocalRepository();
        $globalRepo = $this->globalComposer ? $this->globalComposer->getRepositoryManager()->getLocalRepository() : null;
        if ($repo) {
            $this->loadRepository($repo);
        }
        if ($globalRepo) {
            $this->loadRepository($globalRepo);
        }
    }

    /**
     * Adds a plugin, activates it and registers it with the event dispatcher
     *
     * @param PluginInterface $plugin plugin instance
     */
    public function addPlugin(PluginInterface $plugin)
    {
        if ($this->io->isDebug()) {
            $this->io->writeError('Loading plugin '.get_class($plugin));
        }
        $this->plugins[] =  $plugin;
        $plugin->activate($this->composer, $this->io);

        if ($plugin instanceof EventSubscriberInterface) {
            $this->composer->getEventDispatcher()->addSubscriber($plugin);
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
     * Load all plugins and installers from a repository
     *
     * Note that plugins in the specified repository that rely on events that
     * have fired prior to loading will be missed. This means you likely want to
     * call this method as early as possible.
     *
     * @param RepositoryInterface $repo Repository to scan for plugins to install
     *
     * @throws \RuntimeException
     */
    public function loadRepository(RepositoryInterface $repo)
    {
        foreach ($repo->getPackages() as $package) {
            if ($package instanceof AliasPackage) {
                continue;
            }
            if ('composer-plugin' === $package->getType()) {
                $requiresComposer = null;
                foreach ($package->getRequires() as $link) {
                    if ($link->getTarget() == 'composer-plugin-api') {
                        $requiresComposer = $link->getConstraint();
                    }
                }

                if (!$requiresComposer) {
                    throw new \RuntimeException("Plugin ".$package->getName()." is missing a require statement for a version of the composer-plugin-api package.");
                }

                if (!$requiresComposer->matches(new VersionConstraint('==', $this->versionParser->normalize(PluginInterface::PLUGIN_API_VERSION)))) {
                    $this->io->writeError("<warning>The plugin ".$package->getName()." requires a version of composer-plugin-api that does not match your composer installation. You may need to run composer update with the '--no-plugins' option.</warning>");
                }

                $this->registerPackage($package);
            }
            // Backward compatibility
            if ('composer-installer' === $package->getType()) {
                $this->registerPackage($package);
            }
        }
    }

    /**
     * Recursively generates a map of package names to packages for all deps
     *
     * @param Pool             $pool      Package pool of installed packages
     * @param array            $collected Current state of the map for recursion
     * @param PackageInterface $package   The package to analyze
     *
     * @return array Map of package names to packages
     */
    protected function collectDependencies(Pool $pool, array $collected, PackageInterface $package)
    {
        $requires = array_merge(
            $package->getRequires(),
            $package->getDevRequires()
        );

        foreach ($requires as $requireLink) {
            $requiredPackage = $this->lookupInstalledPackage($pool, $requireLink);
            if ($requiredPackage && !isset($collected[$requiredPackage->getName()])) {
                $collected[$requiredPackage->getName()] = $requiredPackage;
                $collected = $this->collectDependencies($pool, $collected, $requiredPackage);
            }
        }

        return $collected;
    }

    /**
     * Resolves a package link to a package in the installed pool
     *
     * Since dependencies are already installed this should always find one.
     *
     * @param Pool $pool Pool of installed packages only
     * @param Link $link Package link to look up
     *
     * @return PackageInterface|null The found package
     */
    protected function lookupInstalledPackage(Pool $pool, Link $link)
    {
        $packages = $pool->whatProvides($link->getTarget(), $link->getConstraint());

        return (!empty($packages)) ? $packages[0] : null;
    }

    /**
     * Register a plugin package, activate it etc.
     *
     * If it's of type composer-installer it is registered as an installer
     * instead for BC
     *
     * @param PackageInterface $package
     * @param bool             $failOnMissingClasses By default this silently skips plugins that can not be found, but if set to true it fails with an exception
     *
     * @throws \UnexpectedValueException
     */
    public function registerPackage(PackageInterface $package, $failOnMissingClasses = false)
    {
        $oldInstallerPlugin = ($package->getType() === 'composer-installer');

        if (in_array($package->getName(), $this->registeredPlugins)) {
            return;
        }

        $extra = $package->getExtra();
        if (empty($extra['class'])) {
            throw new \UnexpectedValueException('Error while installing '.$package->getPrettyName().', composer-plugin packages should have a class defined in their extra key to be usable.');
        }
        $classes = is_array($extra['class']) ? $extra['class'] : array($extra['class']);

        $localRepo = $this->composer->getRepositoryManager()->getLocalRepository();
        $globalRepo = $this->globalComposer ? $this->globalComposer->getRepositoryManager()->getLocalRepository() : null;

        $pool = new Pool('dev');
        $pool->addRepository($localRepo);
        if ($globalRepo) {
            $pool->addRepository($globalRepo);
        }

        $autoloadPackages = array($package->getName() => $package);
        $autoloadPackages = $this->collectDependencies($pool, $autoloadPackages, $package);

        $generator = $this->composer->getAutoloadGenerator();
        $autoloads = array();
        foreach ($autoloadPackages as $autoloadPackage) {
            $downloadPath = $this->getInstallPath($autoloadPackage, ($globalRepo && $globalRepo->hasPackage($autoloadPackage)));
            $autoloads[] = array($autoloadPackage, $downloadPath);
        }

        $map = $generator->parseAutoloads($autoloads, new Package('dummy', '1.0.0.0', '1.0.0'));
        $classLoader = $generator->createLoader($map);
        $classLoader->register();

        foreach ($classes as $class) {
            if (class_exists($class, false)) {
                $code = file_get_contents($classLoader->findFile($class));
                $code = preg_replace('{^(\s*)class\s+(\S+)}mi', '$1class $2_composer_tmp'.self::$classCounter, $code);
                eval('?>'.$code);
                $class .= '_composer_tmp'.self::$classCounter;
                self::$classCounter++;
            }

            if ($oldInstallerPlugin) {
                $installer = new $class($this->io, $this->composer);
                $this->composer->getInstallationManager()->addInstaller($installer);
            } elseif (class_exists($class)) {
                $plugin = new $class();
                $this->addPlugin($plugin);
                $this->registeredPlugins[] = $package->getName();
            } elseif ($failOnMissingClasses) {
                throw new \UnexpectedValueException('Plugin '.$package->getName().' could not be initialized, class not found: '.$class);
            }
        }
    }

    /**
     * Retrieves the path a package is installed to.
     *
     * @param PackageInterface $package
     * @param bool             $global  Whether this is a global package
     *
     * @return string Install path
     */
    public function getInstallPath(PackageInterface $package, $global = false)
    {
        if (!$global) {
            return $this->composer->getInstallationManager()->getInstallPath($package);
        }

        return $this->globalComposer->getInstallationManager()->getInstallPath($package);
    }
}
