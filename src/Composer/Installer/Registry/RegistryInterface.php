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
 * Installer registry interface.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
interface RegistryInterface
{
    /**
     * Opens registry (read file / opens connection).
     */
    function open();

    /**
     * Closes registry (writes file / closes connection).
     */
    function close();

    /**
     * Checks if specified package registered (installed).
     *
     * @param   PackageInterface    $package    package instance
     *
     * @return  Boolean
     */
    function isPackageRegistered(PackageInterface $package);

    /**
     * Returns installer type for the registered package.
     *
     * @param   PackageInterface    $package    package instance
     *
     * @return  string
     */
    function getRegisteredPackageInstallerType(PackageInterface $package);

    /**
     * Registers package in registry.
     *
     * @param   PackageInterface    $package    package instance
     * @param   string              $type       installer type with which package were been
     *                                          installed
     */
    function registerPackage(PackageInterface $package, $type);

    /**
     * Removes package from registry.
     *
     * @param   PackageInterface    $package    package instance
     */
    function unregisterPackage(PackageInterface $package);
}
