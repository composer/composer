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

use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\Package\PackageInterface;

/**
 * Interface for the package installation manager.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
interface InstallerInterface
{
    /**
     * Checks that provided package is installed.
     *
     * @param   PackageInterface    $package    package instance
     *
     * @return  Boolean
     */
    function isInstalled(PackageInterface $package);

    /**
     * Installs specific package.
     *
     * @param   PackageInterface    $package    package instance
     */
    function install(PackageInterface $package);

    /**
     * Updates specific package.
     *
     * @param   PackageInterface    $initial    already installed package version
     * @param   PackageInterface    $target     updated version
     *
     * @throws  InvalidArgumentException        if $from package is not installed
     */
    function update(PackageInterface $initial, PackageInterface $target);

    /**
     * Uninstalls specific package.
     *
     * @param   PackageInterface    $package    package instance
     */
    function uninstall(PackageInterface $package);

    function getInstallPath(PackageInterface $package);
}
