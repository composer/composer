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

use Composer\Downloader\DownloadManager;
use Composer\Installer\Registry\RegistryInterface;
use Composer\Installer\Registry\FilesystemRegistry;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\Package\PackageInterface;

/**
 * Package installation manager.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class LibraryInstaller implements InstallerInterface
{
    private $dir;
    private $dm;
    private $registry;

    /**
     * Initializes library installer.
     *
     * @param   string              $dir        relative path for packages home
     * @param   DownloadManager     $dm         download manager
     * @param   RegistryInterface   $registry   registry controller
     */
    public function __construct($dir, DownloadManager $dm, RegistryInterface $registry = null)
    {
        $this->dir = $dir;
        $this->dm  = $dm;

        if (!is_dir($this->dir)) {
            if (file_exists($this->dir)) {
                throw new \UnexpectedValueException(
                    $this->dir.' exists and is not a directory.'
                );
            }
            if (!mkdir($this->dir, 0777, true)) {
                throw new \UnexpectedValueException(
                    $this->dir.' does not exist and could not be created.'
                );
            }
        }

        if (null === $registry) {
            $registry = new FilesystemRegistry('.composer', str_replace('/', '_', $dir));
        }

        $this->registry = $registry;
        $this->registry->open();
    }

    /**
     * Closes packages registry.
     */
    public function __destruct()
    {
        $this->registry->close();
    }

    /**
     * Executes specific solver operation.
     *
     * @param   OperationInterface  $operation  solver operation instance
     */
    public function executeOperation(OperationInterface $operation)
    {
        $method = $operation->getJobType();

        if ('update' === $method) {
            $this->$method($operation->getPackage(), $operation->getTargetPackage());
        } else {
            $this->$method($operation->getPackage());
        }
    }

    /**
     * Checks that specific package is installed.
     *
     * @param   PackageInterface    $package    package instance
     *
     * @return  Boolean
     */
    public function isInstalled(PackageInterface $package)
    {
        return $this->registry->isPackageRegistered($package);
    }

    /**
     * Installs specific package.
     *
     * @param   PackageInterface    $package    package instance
     *
     * @throws  InvalidArgumentException        if provided package have no urls to download from
     */
    public function install(PackageInterface $package)
    {
        $type = $this->dm->download($package, $this->dir);
        $this->registry->registerPackage($package, $type);
    }

    /**
     * Updates specific package.
     *
     * @param   PackageInterface    $initial    already installed package version
     * @param   PackageInterface    $target     updated version
     *
     * @throws  InvalidArgumentException        if $from package is not installed
     */
    public function update(PackageInterface $initial, PackageInterface $target)
    {
        if (!$this->registry->isPackageRegistered($initial)) {
            throw new \UnexpectedValueException('Package is not installed: '.$initial);
        }

        $type = $this->registry->getRegisteredPackageInstallerType($initial);
        $this->dm->update($initial, $target, $this->dir, $type);
        $this->registry->unregisterPackage($initial);
        $this->registry->registerPackage($target, $type);
    }

    /**
     * Uninstalls specific package.
     *
     * @param   PackageInterface    $package    package instance
     *
     * @throws  InvalidArgumentException        if package is not installed
     */
    public function uninstall(PackageInterface $package)
    {
        if (!$this->registry->isPackageRegistered($package)) {
            throw new \UnexpectedValueException('Package is not installed: '.$package);
        }

        $type = $this->registry->getRegisteredPackageInstallerType($package);
        $this->dm->remove($package, $this->dir, $type);
        $this->registry->unregisterPackage($package);
    }
}
