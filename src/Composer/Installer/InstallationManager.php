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
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;

/**
 * Package operation manager.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class InstallationManager
{
    private $installers = array();

    /**
     * Sets installer for a specific package type.
     *
     * @param   string              $type       package type (library f.e.)
     * @param   InstallerInterface  $installer  installer instance
     */
    public function setInstaller($type, InstallerInterface $installer)
    {
        $this->installers[$type] = $installer;
    }

    /**
     * Returns installer for a specific package type.
     *
     * @param   string              $type       package type
     *
     * @return  InstallerInterface
     *
     * @throws  InvalidArgumentException        if installer for provided type is not registered
     */
    public function getInstaller($type)
    {
        if (!isset($this->installers[$type])) {
            throw new \InvalidArgumentException('Unknown installer type: '.$type);
        }

        return $this->installers[$type];
    }

    /**
     * Checks whether provided package is installed in one of the registered installers.
     *
     * @param   PackageInterface    $package    package instance
     *
     * @return  Boolean
     */
    public function isPackageInstalled(PackageInterface $package)
    {
        foreach ($this->installers as $installer) {
            if ($installer->isInstalled($package)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Executes solver operation.
     *
     * @param   OperationInterface  $operation  operation instance
     */
    public function execute(OperationInterface $operation)
    {
        $method = $operation->getJobType();
        $this->$method($operation);
    }

    /**
     * Executes install operation.
     *
     * @param   InstallOperation    $operation  operation instance
     */
    public function install(InstallOperation $operation)
    {
        $installer = $this->getInstaller($operation->getPackage()->getType());
        $installer->install($operation->getPackage());
    }

    /**
     * Executes update operation.
     *
     * @param   InstallOperation    $operation  operation instance
     */
    public function update(UpdateOperation $operation)
    {
        $initial = $operation->getInitialPackage();
        $target  = $operation->getTargetPackage();

        $initialType = $initial->getType();
        $targetType  = $target->getType();

        if ($initialType === $targetType) {
            $installer = $this->getInstaller($initialType);
            $installer->update($initial, $target);
        } else {
            $this->getInstaller($initialType)->uninstall($initial);
            $this->getInstaller($targetType)->install($target);
        }
    }

    /**
     * Uninstalls package.
     *
     * @param   UninstallOperation  $operation  operation instance
     */
    public function uninstall(UninstallOperation $operation)
    {
        $installer = $this->getInstaller($operation->getPackage()->getType());
        $installer->uninstall($operation->getPackage());
    }

    public function getInstallPath(PackageInterface $package)
    {
        $installer = $this->getInstaller($package->getType());
        return $installer->getInstallPath($package);
    }
}
