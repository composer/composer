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
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class InstallationManager
{
    private $installers = array();
    private $cache = array();
    private $vendorPath;

    /**
     * Creates an instance of InstallationManager
     * 
     * @param    string    $vendorPath    Relative path to the vendor directory
     * @throws   \InvalidArgumentException
     */
    public function __construct($vendorPath = 'vendor')
    {
        if (substr($vendorPath, 0, 1) === '/' || substr($vendorPath, 1, 1) === ':') {
            $basePath = getcwd();
            if (0 !== strpos($vendorPath, $basePath)) {
                throw new \InvalidArgumentException("Vendor path ($vendorPath) must be within the current working directory ($basePath).");
            }
            // convert to relative path
            $this->vendorPath = substr($vendorPath, strlen($basePath)+1);
        } else {
            $this->vendorPath = $vendorPath;
        }
    }

    /**
     * Adds installer
     *
     * @param   InstallerInterface  $installer  installer instance
     */
    public function addInstaller(InstallerInterface $installer)
    {
        array_unshift($this->installers, $installer);
        $this->cache = array();
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

    /**
     * Returns the installation path of a package
     *
     * @param   PackageInterface    $package
     * @return  string path
     */
    public function getInstallPath(PackageInterface $package)
    {
        $installer = $this->getInstaller($package->getType());
        return $installer->getInstallPath($package);
    }

    /**
     * Returns the vendor path
     * 
     * @param   boolean  $absolute  Whether or not to return an absolute path
     * @return  string path
     */
    public function getVendorPath($absolute = false)
    {
        if (!$absolute) {
            return $this->vendorPath;
        }

        return getcwd().DIRECTORY_SEPARATOR.$this->vendorPath;
    }
}