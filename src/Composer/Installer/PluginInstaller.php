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

namespace Composer\Installer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\PartialComposer;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginManager;
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
    public function __construct(IOInterface $io, PartialComposer $composer, Filesystem $fs = null, BinaryInstaller $binaryInstaller = null)
    {
        parent::__construct($io, $composer, 'composer-plugin', $fs, $binaryInstaller);
    }

    /**
     * @inheritDoc
     */
    public function supports(string $packageType)
    {
        return $packageType === 'composer-plugin' || $packageType === 'composer-installer';
    }

    /**
     * @inheritDoc
     */
    public function prepare($type, PackageInterface $package, PackageInterface $prevPackage = null)
    {
        // fail install process early if it is going to fail due to a plugin not being allowed
        if (($type === 'install' || $type === 'update') && !$this->getPluginManager()->arePluginsDisabled('local')) {
            $this->getPluginManager()->isPluginAllowed($package->getName(), false);
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
            $promise = \React\Promise\resolve(null);
        }

        return $promise->then(function () use ($package, $repo): void {
            try {
                Platform::workaroundFilesystemIssues();
                $this->getPluginManager()->registerPackage($package, true);
            } catch (\Exception $e) {
                $this->rollbackInstall($e, $repo, $package);
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
            $promise = \React\Promise\resolve(null);
        }

        return $promise->then(function () use ($initial, $target, $repo): void {
            try {
                Platform::workaroundFilesystemIssues();
                $this->getPluginManager()->deactivatePackage($initial);
                $this->getPluginManager()->registerPackage($target, true);
            } catch (\Exception $e) {
                $this->rollbackInstall($e, $repo, $target);
            }
        });
    }

    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $this->getPluginManager()->uninstallPackage($package);

        return parent::uninstall($repo, $package);
    }

    private function rollbackInstall(\Exception $e, InstalledRepositoryInterface $repo, PackageInterface $package): void
    {
        $this->io->writeError('Plugin initialization failed ('.$e->getMessage().'), uninstalling plugin');
        parent::uninstall($repo, $package);
        throw $e;
    }

    protected function getPluginManager(): PluginManager
    {
        assert($this->composer instanceof Composer, new \LogicException(self::class.' should be initialized with a fully loaded Composer instance.'));
        $pluginManager = $this->composer->getPluginManager();

        return $pluginManager;
    }
}
