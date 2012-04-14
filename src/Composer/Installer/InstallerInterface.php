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
use Composer\Repository\WritableRepositoryInterface;

/**
 * Interface for the package installation manager.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
interface InstallerInterface
{
    /**
     * Decides if the installer supports the given type
     *
     * @param   string  $packageType
     * @return  Boolean
     */
    function supports($packageType);

    /**
     * Checks that provided package is installed.
     *
     * @param   WritableRepositoryInterface $repo    repository in which to check
     * @param   PackageInterface    $package    package instance
     *
     * @return  Boolean
     */
    function isInstalled(WritableRepositoryInterface $repo, PackageInterface $package);

    /**
     * Installs specific package.
     *
     * @param   WritableRepositoryInterface $repo    repository in which to check
     * @param   PackageInterface    $package    package instance
     */
    function install(WritableRepositoryInterface $repo, PackageInterface $package);

    /**
     * Updates specific package.
     *
     * @param   WritableRepositoryInterface $repo    repository in which to check
     * @param   PackageInterface    $initial    already installed package version
     * @param   PackageInterface    $target     updated version
     *
     * @throws  InvalidArgumentException        if $from package is not installed
     */
    function update(WritableRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target);

    /**
     * Uninstalls specific package.
     *
     * @param   WritableRepositoryInterface $repo    repository in which to check
     * @param   PackageInterface    $package    package instance
     */
    function uninstall(WritableRepositoryInterface $repo, PackageInterface $package);

    /**
     * Returns the installation path of a package
     *
     * @param   PackageInterface    $package
     * @return  string path
     */
    function getInstallPath(PackageInterface $package);
}
