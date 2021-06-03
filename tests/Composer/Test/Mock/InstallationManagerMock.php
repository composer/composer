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
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Package\PackageInterface;
use Composer\IO\IOInterface;
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

    public function __construct()
    {
    }

    public function execute(InstalledRepositoryInterface $repo, array $operations, $devMode = true, $runScripts = true)
    {
        foreach ($operations as $operation) {
            $method = $operation->getOperationType();
            // skipping download() step here for tests
            $this->$method($repo, $operation);
        }
    }

    public function getInstallPath(PackageInterface $package)
    {
        return '';
    }

    public function isPackageInstalled(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        return $repo->hasPackage($package);
    }

    public function install(InstalledRepositoryInterface $repo, InstallOperation $operation)
    {
        $this->installed[] = $operation->getPackage();
        $this->trace[] = strip_tags((string) $operation);
        $repo->addPackage(clone $operation->getPackage());
    }

    public function update(InstalledRepositoryInterface $repo, UpdateOperation $operation)
    {
        $this->updated[] = array($operation->getInitialPackage(), $operation->getTargetPackage());
        $this->trace[] = strip_tags((string) $operation);
        $repo->removePackage($operation->getInitialPackage());
        if (!$repo->hasPackage($operation->getTargetPackage())) {
            $repo->addPackage(clone $operation->getTargetPackage());
        }
    }

    public function uninstall(InstalledRepositoryInterface $repo, UninstallOperation $operation)
    {
        $this->uninstalled[] = $operation->getPackage();
        $this->trace[] = strip_tags((string) $operation);
        $repo->removePackage($operation->getPackage());
    }

    public function markAliasInstalled(InstalledRepositoryInterface $repo, MarkAliasInstalledOperation $operation)
    {
        $package = $operation->getPackage();

        $this->installed[] = $package;
        $this->trace[] = strip_tags((string) $operation);

        parent::markAliasInstalled($repo, $operation);
    }

    public function markAliasUninstalled(InstalledRepositoryInterface $repo, MarkAliasUninstalledOperation $operation)
    {
        $this->uninstalled[] = $operation->getPackage();
        $this->trace[] = strip_tags((string) $operation);

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

    public function notifyInstalls(IOInterface $io)
    {
        // noop
    }

    public function getInstalledPackagesByType()
    {
        return $this->installed;
    }
}
