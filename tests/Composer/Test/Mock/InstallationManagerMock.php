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

namespace Composer\Test\Mock;

use Composer\Installer\InstallationManager;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Package\PackageInterface;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\MarkAliasInstalledOperation;
use Composer\DependencyResolver\Operation\MarkAliasUninstalledOperation;

class InstallationManagerMock extends InstallationManager
{
    private $installed = array();
    private $updated = array();
    private $uninstalled = array();
    private $trace = array();

    public function getInstallPath(PackageInterface $package)
    {
        return '';
    }

    public function isPackageInstalled(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        return $repo->hasPackage($package);
    }

    public function install(RepositoryInterface $repo, InstallOperation $operation)
    {
        $this->installed[] = $operation->getPackage();
        $this->trace[] = (string) $operation;
        $repo->addPackage(clone $operation->getPackage());
    }

    public function update(RepositoryInterface $repo, UpdateOperation $operation)
    {
        $this->updated[] = array($operation->getInitialPackage(), $operation->getTargetPackage());
        $this->trace[] = (string) $operation;
        $repo->removePackage($operation->getInitialPackage());
        $repo->addPackage(clone $operation->getTargetPackage());
    }

    public function uninstall(RepositoryInterface $repo, UninstallOperation $operation)
    {
        $this->uninstalled[] = $operation->getPackage();
        $this->trace[] = (string) $operation;
        $repo->removePackage($operation->getPackage());
    }

    public function markAliasInstalled(RepositoryInterface $repo, MarkAliasInstalledOperation $operation)
    {
        $package = $operation->getPackage();

        $this->installed[] = $package;
        $this->trace[] = (string) $operation;

        parent::markAliasInstalled($repo, $operation);
    }

    public function markAliasUninstalled(RepositoryInterface $repo, MarkAliasUninstalledOperation $operation)
    {
        $this->uninstalled[] = $operation->getPackage();
        $this->trace[] = (string) $operation;

        parent::markAliasUninstalled($repo, $operation);
    }

    public function getTrace()
    {
        return $this->trace;
    }

    public function getInstalledPackages()
    {
        return $this->installed;
    }

    public function getUpdatedPackages()
    {
        return $this->updated;
    }

    public function getUninstalledPackages()
    {
        return $this->uninstalled;
    }
}
