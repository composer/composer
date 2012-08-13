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

use Composer\Package\PackageInterface;
use Composer\Package\AliasPackage;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\NotifiableRepositoryInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\MarkAliasInstalledOperation;
use Composer\DependencyResolver\Operation\MarkAliasUninstalledOperation;

/**
 * Package operation manager.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class InstallationManager
{
    private $installers = array();
    private $cache = array();

    /**
     * Adds installer
     *
     * @param InstallerInterface $installer installer instance
     */
    public function addInstaller(InstallerInterface $installer)
    {
        array_unshift($this->installers, $installer);
        $this->cache = array();
    }

    /**
     * Returns installer for a specific package type.
     *
     * @param string $type package type
     *
     * @return InstallerInterface
     *
     * @throws InvalidArgumentException if installer for provided type is not registered
     */
    public function getInstaller($type)
    {
        $type = strtolower($type);

        if (isset($this->cache[$type])) {
            return $this->cache[$type];
        }

        foreach ($this->installers as $installer) {
            if ($installer->supports($type)) {
                return $this->cache[$type] = $installer;
            }
        }

        throw new \InvalidArgumentException('Unknown installer type: '.$type);
    }

    /**
     * Checks whether provided package is installed in one of the registered installers.
     *
     * @param InstalledRepositoryInterface $repo    repository in which to check
     * @param PackageInterface             $package package instance
     *
     * @return bool
     */
    public function isPackageInstalled(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        if ($package instanceof AliasPackage) {
            return $repo->hasPackage($package) && $this->isPackageInstalled($repo, $package->getAliasOf());
        }

        return $this->getInstaller($package->getType())->isInstalled($repo, $package);
    }

    /**
     * Executes solver operation.
     *
     * @param RepositoryInterface $repo      repository in which to check
     * @param OperationInterface  $operation operation instance
     */
    public function execute(RepositoryInterface $repo, OperationInterface $operation)
    {
        $method = $operation->getJobType();
        $this->$method($repo, $operation);
    }

    /**
     * Executes install operation.
     *
     * @param RepositoryInterface $repo      repository in which to check
     * @param InstallOperation    $operation operation instance
     */
    public function install(RepositoryInterface $repo, InstallOperation $operation)
    {
        $package = $operation->getPackage();
        $installer = $this->getInstaller($package->getType());
        $installer->install($repo, $package);
        $this->notifyInstall($package);
    }

    /**
     * Executes update operation.
     *
     * @param RepositoryInterface $repo      repository in which to check
     * @param InstallOperation    $operation operation instance
     */
    public function update(RepositoryInterface $repo, UpdateOperation $operation)
    {
        $initial = $operation->getInitialPackage();
        $target = $operation->getTargetPackage();

        $initialType = $initial->getType();
        $targetType  = $target->getType();

        if ($initialType === $targetType) {
            $installer = $this->getInstaller($initialType);
            $installer->update($repo, $initial, $target);
            $this->notifyInstall($target);
        } else {
            $this->getInstaller($initialType)->uninstall($repo, $initial);
            $this->getInstaller($targetType)->install($repo, $target);
        }
    }

    /**
     * Uninstalls package.
     *
     * @param RepositoryInterface $repo      repository in which to check
     * @param UninstallOperation  $operation operation instance
     */
    public function uninstall(RepositoryInterface $repo, UninstallOperation $operation)
    {
        $package = $operation->getPackage();
        $installer = $this->getInstaller($package->getType());
        $installer->uninstall($repo, $package);
    }

    /**
     * Executes markAliasInstalled operation.
     *
     * @param RepositoryInterface         $repo      repository in which to check
     * @param MarkAliasInstalledOperation $operation operation instance
     */
    public function markAliasInstalled(RepositoryInterface $repo, MarkAliasInstalledOperation $operation)
    {
        $package = $operation->getPackage();

        if (!$repo->hasPackage($package)) {
            $repo->addPackage(clone $package);
        }
    }

    /**
     * Executes markAlias operation.
     *
     * @param RepositoryInterface           $repo      repository in which to check
     * @param MarkAliasUninstalledOperation $operation operation instance
     */
    public function markAliasUninstalled(RepositoryInterface $repo, MarkAliasUninstalledOperation $operation)
    {
        $package = $operation->getPackage();

        $repo->removePackage($package);
    }

    /**
     * Returns the installation path of a package
     *
     * @param  PackageInterface $package
     * @return string           path
     */
    public function getInstallPath(PackageInterface $package)
    {
        $installer = $this->getInstaller($package->getType());

        return $installer->getInstallPath($package);
    }

    private function notifyInstall(PackageInterface $package)
    {
        if ($package->getRepository() instanceof NotifiableRepositoryInterface) {
            $package->getRepository()->notifyInstall($package);
        }
    }
}
