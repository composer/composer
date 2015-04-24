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

namespace Composer\Installer;

use Composer\Composer;
use Composer\Package\Package;
use Composer\IO\IOInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Package\PackageInterface;
use React\Promise\PromiseInterface;

/**
 * Installer for plugin packages
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Nils Adermann <naderman@naderman.de>
 */
class PluginInstaller extends LibraryInstaller
{
    private static $classCounter = 0;

    /**
     * Initializes Plugin installer.
     *
     * @param IOInterface $io
     * @param Composer    $composer
     */
    public function __construct(IOInterface $io, Composer $composer)
    {
        parent::__construct($io, $composer, 'composer-plugin');
    }

    /**
     * {@inheritDoc}
     */
    public function supports($packageType)
    {
        return $packageType === 'composer-plugin' || $packageType === 'composer-installer';
    }

    /**
     * {@inheritDoc}
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package, $loop = null)
    {
        $extra = $package->getExtra();
        if (empty($extra['class'])) {
            throw new \UnexpectedValueException('Error while installing '.$package->getPrettyName().', composer-plugin packages should have a class defined in their extra key to be usable.');
        }

        return $this->onInstall(parent::install($repo, $package, $loop), $package);
    }

    /**
     * {@inheritDoc}
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target, $loop = null)
    {
        $extra = $target->getExtra();
        if (empty($extra['class'])) {
            throw new \UnexpectedValueException('Error while installing '.$target->getPrettyName().', composer-plugin packages should have a class defined in their extra key to be usable.');
        }

        return $this->onInstall(parent::update($repo, $initial, $target, $loop), $target);
    }

    private function onInstall($result, $package)
    {
        $pluginManager = $this->composer->getPluginManager();

        if ($result instanceof PromiseInterface) {
            return $result->then(function () use ($package, $pluginManager) {
                $pluginManager->registerPackage($package, true);
            });
        }

        $pluginManager->registerPackage($package, true);
    }
}
