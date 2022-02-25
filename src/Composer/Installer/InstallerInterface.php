<?php declare(strict_types=1);

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
use Composer\Repository\InstalledRepositoryInterface;
use InvalidArgumentException;
use React\Promise\PromiseInterface;

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
     * @param  string $packageType
     * @return bool
     */
    public function supports(string $packageType);

    /**
     * Checks that provided package is installed.
     *
     * @param InstalledRepositoryInterface $repo    repository in which to check
     * @param PackageInterface             $package package instance
     *
     * @return bool
     */
    public function isInstalled(InstalledRepositoryInterface $repo, PackageInterface $package);

    /**
     * Downloads the files needed to later install the given package.
     *
     * @param  PackageInterface      $package     package instance
     * @param  PackageInterface      $prevPackage previous package instance in case of an update
     * @return PromiseInterface|null
     */
    public function download(PackageInterface $package, PackageInterface $prevPackage = null);

    /**
     * Do anything that needs to be done between all downloads have been completed and the actual operation is executed
     *
     * All packages get first downloaded, then all together prepared, then all together installed/updated/uninstalled. Therefore
     * for error recovery it is important to avoid failing during install/update/uninstall as much as possible, and risky things or
     * user prompts should happen in the prepare step rather. In case of failure, cleanup() will be called so that changes can
     * be undone as much as possible.
     *
     * @param  string                $type        one of install/update/uninstall
     * @param  PackageInterface      $package     package instance
     * @param  PackageInterface      $prevPackage previous package instance in case of an update
     * @return PromiseInterface|null
     */
    public function prepare(string $type, PackageInterface $package, PackageInterface $prevPackage = null);

    /**
     * Installs specific package.
     *
     * @param  InstalledRepositoryInterface $repo    repository in which to check
     * @param  PackageInterface             $package package instance
     * @return PromiseInterface|null
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package);

    /**
     * Updates specific package.
     *
     * @param  InstalledRepositoryInterface $repo    repository in which to check
     * @param  PackageInterface             $initial already installed package version
     * @param  PackageInterface             $target  updated version
     * @throws InvalidArgumentException     if $initial package is not installed
     * @return PromiseInterface|null
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target);

    /**
     * Uninstalls specific package.
     *
     * @param  InstalledRepositoryInterface $repo    repository in which to check
     * @param  PackageInterface             $package package instance
     * @return PromiseInterface|null
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package);

    /**
     * Do anything to cleanup changes applied in the prepare or install/update/uninstall steps
     *
     * Note that cleanup will be called for all packages regardless if they failed an operation or not, to give
     * all installers a change to cleanup things they did previously, so you need to keep track of changes
     * applied in the installer/downloader themselves.
     *
     * @param  string                $type        one of install/update/uninstall
     * @param  PackageInterface      $package     package instance
     * @param  PackageInterface      $prevPackage previous package instance in case of an update
     * @return PromiseInterface|null
     */
    public function cleanup(string $type, PackageInterface $package, PackageInterface $prevPackage = null);

    /**
     * Returns the absolute installation path of a package.
     *
     * @param  PackageInterface $package
     * @return string           absolute path to install to, which MUST not end with a slash
     */
    public function getInstallPath(PackageInterface $package);
}
