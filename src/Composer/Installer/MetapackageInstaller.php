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

use Composer\Repository\InstalledRepositoryInterface;
use Composer\Package\PackageInterface;
use Composer\IO\IOInterface;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;

/**
 * Metapackage installation manager.
 *
 * @author Martin Hasoň <martin.hason@gmail.com>
 */
class MetapackageInstaller implements InstallerInterface
{
    private $io;

    public function __construct(IOInterface $io)
    {
        $this->io = $io;
    }

    /**
     * {@inheritDoc}
     */
    public function supports($packageType)
    {
        return $packageType === 'metapackage';
    }

    /**
     * {@inheritDoc}
     */
    public function isInstalled(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        return $repo->hasPackage($package);
    }

    /**
     * {@inheritDoc}
     */
    public function download(PackageInterface $package, PackageInterface $prevPackage = null)
    {
        // noop
        return \React\Promise\resolve();
    }

    /**
     * {@inheritDoc}
     */
    public function prepare($type, PackageInterface $package, PackageInterface $prevPackage = null)
    {
        // noop
        return \React\Promise\resolve();
    }

    /**
     * {@inheritDoc}
     */
    public function cleanup($type, PackageInterface $package, PackageInterface $prevPackage = null)
    {
        // noop
        return \React\Promise\resolve();
    }

    /**
     * {@inheritDoc}
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $this->io->writeError("  - " . InstallOperation::format($package));

        $repo->addPackage(clone $package);

        return \React\Promise\resolve();
    }

    /**
     * {@inheritDoc}
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        if (!$repo->hasPackage($initial)) {
            throw new \InvalidArgumentException('Package is not installed: '.$initial);
        }

        $this->io->writeError("  - " . UpdateOperation::format($initial, $target));

        $repo->removePackage($initial);
        $repo->addPackage(clone $target);

        return \React\Promise\resolve();
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        if (!$repo->hasPackage($package)) {
            throw new \InvalidArgumentException('Package is not installed: '.$package);
        }

        $this->io->writeError("  - " . UninstallOperation::format($package));

        $repo->removePackage($package);

        return \React\Promise\resolve();
    }

    /**
     * {@inheritDoc}
     */
    public function getInstallPath(PackageInterface $package)
    {
        return '';
    }
}
