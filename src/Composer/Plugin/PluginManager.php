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
use Composer\Repository\RepositoryInterface;
use Composer\Package\PackageInterface;
use Composer\Package\Link;
use Composer\DependencyResolver\Pool;

/**
 * Plugin manager
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class PluginManager
{
    protected $composer;
    protected $io;
    protected $globalRepository;

    protected $plugins = array();

    private static $classCounter = 0;

    /**
     * Initializes plugin manager
     *
     * @param Composer $composer
     */
    public function __construct(Composer $composer, IOInterface $io, RepositoryInterface $globalRepository = null)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->globalRepository = $globalRepository;
    }

    /**
     * Loads all plugins from currently installed plugin packages
     */
    public function loadInstalledPlugins()
    {
        $repo = $this->composer->getRepositoryManager()->getLocalRepository();

        if ($repo) {
            $this->loadRepository($repo);
        }
        if ($this->globalRepository) {
            $this->loadRepository($this->globalRepository);
        }
    }

    /**
     * Adds a plugin, activates it and registers it with the event dispatcher
     *
     * @param PluginInterface $plugin plugin instance
     */
    public function addPlugin(PluginInterface $plugin)
    {
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

    protected function loadRepository(RepositoryInterface $repo)
    {
        foreach ($repo->getPackages() as $package) {
            if ('composer-plugin' === $package->getType() || 'composer-installer' === $package->getType()) {
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
     */
    public function registerPackage(PackageInterface $package)
    {
        $oldInstallerPlugin = ($package->getType() === 'composer-installer');

        $extra = $package->getExtra();
        if (empty($extra['class'])) {
            throw new \UnexpectedValueException('Error while installing '.$package->getPrettyName().', composer-plugin packages should have a class defined in their extra key to be usable.');
        }
        $classes = is_array($extra['class']) ? $extra['class'] : array($extra['class']);

        $pool = new Pool('dev');
        $localRepo = $this->composer->getRepositoryManager()->getLocalRepository();
        $pool->addRepository($localRepo);
        if ($this->globalRepository) {
            $pool->addRepository($this->globalRepository);
        }

        $autoloadPackages = array($package->getName() => $package);
        $autoloadPackages = $this->collectDependencies($pool, $autoloadPackages, $package);

        $generator = $this->composer->getAutoloadGenerator();
        $autoloads = array();
        foreach ($autoloadPackages as $autoloadPackage) {
            $downloadPath = $this->getInstallPath($autoloadPackage, !$localRepo->hasPackage($autoloadPackage));
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
            } else {
                $plugin = new $class();
                $this->addPlugin($plugin);
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
        $targetDir = $package->getTargetDir();

        return $this->getPackageBasePath($package, $global) . ($targetDir ? '/'.$targetDir : '');
    }

    /**
     * Retrieves the base path a package gets installed into.
     *
     * Does not take targetDir into account.
     *
     * @param PackageInterface $package
     * @param bool             $global  Whether this is a global package
     *
     * @return string Base path
     */
    protected function getPackageBasePath(PackageInterface $package, $global = false)
    {
        if ($global) {
            $vendorDir = $this->composer->getConfig()->get('home').'/vendor';
        } else {
            $vendorDir = rtrim($this->composer->getConfig()->get('vendor-dir'), '/');
        }
        return ($vendorDir ? $vendorDir.'/' : '') . $package->getPrettyName();
    }
}
