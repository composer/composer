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
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;

class InstallationManagerMock extends InstallationManager
{
    private $installed = array();
    private $updated = array();
    private $uninstalled = array();

    public function __construct($vendorDir = 'vendor')
    {
        $this->vendorPath = $vendorDir;
    }

    public function install(RepositoryInterface $repo, InstallOperation $operation)
    {
        $package = $this->antiAlias($operation->getPackage());
        $this->installed[] = $package;
    }

    public function update(RepositoryInterface $repo, UpdateOperation $operation)
    {
        $initial = $this->antiAlias($operation->getInitialPackage());
        $target = $this->antiAlias($operation->getTargetPackage());
        $this->updated[] = $target;
    }

    public function uninstall(RepositoryInterface $repo, UninstallOperation $operation)
    {
        $package = $this->antiAlias($operation->getPackage());
        $this->uninstalled[] = $package;
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
