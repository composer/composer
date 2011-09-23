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

namespace Composer\Installer\Registry;

use Composer\Package\PackageInterface;

/**
 * Filesystem registry.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class FilesystemRegistry implements RegistryInterface
{
    private $registryFile;
    private $registry = array();

    /**
     * Initializes filesystem registry.
     *
     * @param   string  $group  registry (installer) group
     */
    public function __construct($composerCachePath, $group)
    {
        $this->registryFile = rtrim($composerCachePath, '/').'/'.$group.'-reg.json';
        $registryPath = dirname($this->registryFile);

        if (!is_dir($registryPath)) {
            if (file_exists($registryPath)) {
                throw new \UnexpectedValueException(
                    $registryPath.' exists and is not a directory.'
                );
            }
            if (!mkdir($registryPath, 0777, true)) {
                throw new \UnexpectedValueException(
                    $registryPath.' does not exist and could not be created.'
                );
            }
        }
    }

    /**
     * Opens registry (read file / opens connection).
     */
    public function open()
    {
        if (is_file($this->registryFile)) {
            $this->registry = json_decode(file_get_contents($this->registryFile), true);
        }
    }

    /**
     * Closes registry (writes file / closes connection).
     */
    public function close()
    {
        file_put_contents($this->registryFile, json_encode($this->registry));
    }

    /**
     * Checks if specified package registered (installed).
     *
     * @param   PackageInterface    $package    package instance
     *
     * @return  Boolean
     */
    public function isPackageRegistered(PackageInterface $package)
    {
        $packageId = $package->getUniqueName();

        return isset($this->registry[$packageId]);
    }

    /**
     * Returns installer type for the registered package.
     *
     * @param   PackageInterface    $package    package instance
     *
     * @return  string
     */
    public function getRegisteredPackageInstallerType(PackageInterface $package)
    {
        $packageId = $package->getUniqueName();

        if (isset($this->registry[$packageId])) {
            return $this->registry[$packageId];
        }
    }

    /**
     * Registers package in registry.
     *
     * @param   PackageInterface    $package    package instance
     * @param   string              $type       installer type with which package were been
     *                                          installed
     */
    public function registerPackage(PackageInterface $package, $type)
    {
        $packageId = $package->getUniqueName();

        $this->registry[$packageId] = $type;
    }

    /**
     * Removes package from registry.
     *
     * @param   PackageInterface    $package    package instance
     */
    public function unregisterPackage(PackageInterface $package)
    {
        $packageId = $package->getUniqueName();

        if (isset($this->registry[$packageId])) {
            unset($this->registry[$packageId]);
        }
    }
}
