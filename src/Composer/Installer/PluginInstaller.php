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
use Composer\IO\IOInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Composer\Util\Platform;
use React\Promise\PromiseInterface;

/**
 * Installer for plugin packages
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Nils Adermann <naderman@naderman.de>
 */
class PluginInstaller extends LibraryInstaller
{
    /**
     * Initializes Plugin installer.
     *
     * @param IOInterface $io
     * @param Composer    $composer
     */
    public function __construct(IOInterface $io, Composer $composer, Filesystem $fs = null, BinaryInstaller $binaryInstaller = null)
    {
        parent::__construct($io, $composer, 'composer-plugin', $fs, $binaryInstaller);
    }

    /**
     * @inheritDoc
     */
    public function supports($packageType)
    {
        return $packageType === 'composer-plugin' || $packageType === 'composer-installer';
    }

    /**
     * @inheritDoc
     */
    public function prepare($type, PackageInterface $package, PackageInterface $prevPackage = null)
    {
        // fail install process early if it is going to fail due to a plugin not being allowed
        if (($type === 'install' || $type === 'update') && !$this->composer->getPluginManager()->arePluginsDisabled('local')) {
            $extra = $package->getExtra();
            $this->composer->getPluginManager()->isPluginAllowed($package->getName(), false, isset($extra['plugin-optional']) && true === $extra['plugin-optional']);
        }

        return parent::prepare($type, $package, $prevPackage);
    }

    /**
     * @inheritDoc
     */
    public function download(PackageInterface $package, PackageInterface $prevPackage = null)
    {
        $extra = $package->getExtra();
        if (empty($extra['class'])) {
            throw new \UnexpectedValueException('Error while installing '.$package->getPrettyName().', composer-plugin packages should have a class defined in their extra key to be usable.');
        }

        return parent::download($package, $prevPackage);
    }

    /**
     * @inheritDoc
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $promise = parent::install($repo, $package);
        if (!$promise instanceof PromiseInterface) {
            $promise = \React\Promise\resolve();
        }

        $pluginManager = $this->composer->getPluginManager();
        $self = $this;

        return $promise->then(function () use ($self, $pluginManager, $package, $repo) {
            try {
                Platform::workaroundFilesystemIssues();
                $pluginManager->registerPackage($package, true);
            } catch (\Exception $e) {
                $self->rollbackInstall($e, $repo, $package);
            }
        });
    }

    /**
     * @inheritDoc
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        $promise = parent::update($repo, $initial, $target);
        if (!$promise instanceof PromiseInterface) {
            $promise = \React\Promise\resolve();
        }

        $pluginManager = $this->composer->getPluginManager();
        $self = $this;

        return $promise->then(function () use ($self, $pluginManager, $initial, $target, $repo) {
            try {
                Platform::workaroundFilesystemIssues();
                $pluginManager->deactivatePackage($initial);
                $pluginManager->registerPackage($target, true);
            } catch (\Exception $e) {
                $self->rollbackInstall($e, $repo, $target);
            }
        });
    }

    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $this->composer->getPluginManager()->uninstallPackage($package);

        return parent::uninstall($repo, $package);
    }

    /**
     * TODO v3 should make this private once we can drop PHP 5.3 support
     * @private
     *
     * @return void
     */
    public function rollbackInstall(\Exception $e, InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $this->io->writeError('Plugin initialization failed ('.$e->getMessage().'), uninstalling plugin');
        parent::uninstall($repo, $package);
        throw $e;
    }
}
