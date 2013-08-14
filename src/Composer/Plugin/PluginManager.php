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
use Composer\Package\Package;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;

/**
 * Plugin manager
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class PluginManager
{
    protected $composer;
    protected $io;

    protected $plugins = array();

    private static $classCounter = 0;

    /**
     * Initializes plugin manager
     *
     * @param Composer $composer
     */
    public function __construct(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function loadInstalledPlugins()
    {
        $repo = $this->composer->getRepositoryManager()->getLocalRepository();

        if ($repo) {
            foreach ($repo->getPackages() as $package) {
                if ('composer-plugin' === $package->getType() || 'composer-installer' === $package->getType()) {
                    $this->registerPackage($package);
                }
            }
        }
    }

    /**
     * Adds plugin
     *
     * @param PluginInterface $plugin plugin instance
     */
    public function addPlugin(PluginInterface $plugin)
    {
        $this->plugins[] =  $plugin;
        $plugin->activate($this->composer);

        if ($plugin instanceof EventSubscriberInterface) {
            $this->composer->getEventDispatcher()->addSubscriber($plugin);
        }
    }

    public function getPlugins()
    {
        return $this->plugins;
    }

    public function registerPackage(PackageInterface $package)
    {
        $oldInstallerPlugin = ($package->getType() === 'composer-installer');

        $downloadPath = $this->getInstallPath($package);

        $extra = $package->getExtra();
        if (empty($extra['class'])) {
            throw new \UnexpectedValueException('Error while installing '.$package->getPrettyName().', composer-plugin packages should have a class defined in their extra key to be usable.');
        }
        $classes = is_array($extra['class']) ? $extra['class'] : array($extra['class']);

        $generator = $this->composer->getAutoloadGenerator();
        $map = $generator->parseAutoloads(array(array($package, $downloadPath)), new Package('dummy', '1.0.0.0', '1.0.0'));
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

            $plugin = new $class($this->io, $this->composer);

            if ($oldInstallerPlugin) {
                $this->composer->getInstallationManager()->addInstaller($installer);
            } else {
                $this->addPlugin($plugin);
            }
        }
    }

    public function getInstallPath(PackageInterface $package)
    {
        $targetDir = $package->getTargetDir();

        return $this->getPackageBasePath($package) . ($targetDir ? '/'.$targetDir : '');
    }

    protected function getPackageBasePath(PackageInterface $package)
    {
        $vendorDir = rtrim($this->composer->getConfig()->get('vendor-dir'), '/');
        return ($vendorDir ? $vendorDir.'/' : '') . $package->getPrettyName();
    }
}
