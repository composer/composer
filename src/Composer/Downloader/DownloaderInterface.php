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

namespace Composer\Downloader;

use Composer\Package\PackageInterface;
use React\Promise\PromiseInterface;

/**
 * Downloader interface.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
interface DownloaderInterface
{
    /**
     * Returns installation source (either source or dist).
     *
     * @return string "source" or "dist"
     */
    public function getInstallationSource(): string;

    /**
     * This should do any network-related tasks to prepare for an upcoming install/update
     *
     * @param  string $path download path
     * @return PromiseInterface
     */
    public function download(PackageInterface $package, string $path, PackageInterface $prevPackage = null): PromiseInterface;

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
     * @param  string                $path        download path
     * @param  PackageInterface      $prevPackage previous package instance in case of an update
     * @return PromiseInterface
     */
    public function prepare(string $type, PackageInterface $package, string $path, PackageInterface $prevPackage = null): PromiseInterface;

    /**
     * Installs specific package into specific folder.
     *
     * @param  PackageInterface      $package package instance
     * @param  string                $path    download path
     * @return PromiseInterface
     */
    public function install(PackageInterface $package, string $path): PromiseInterface;

    /**
     * Updates specific package in specific folder from initial to target version.
     *
     * @param  PackageInterface      $initial initial package
     * @param  PackageInterface      $target  updated package
     * @param  string                $path    download path
     * @return PromiseInterface
     */
    public function update(PackageInterface $initial, PackageInterface $target, string $path): PromiseInterface;

    /**
     * Removes specific package from specific folder.
     *
     * @param  PackageInterface      $package package instance
     * @param  string                $path    download path
     * @return PromiseInterface
     */
    public function remove(PackageInterface $package, string $path): PromiseInterface;

    /**
     * Do anything to cleanup changes applied in the prepare or install/update/uninstall steps
     *
     * Note that cleanup will be called for all packages, either after install/update/uninstall is complete,
     * or if any package failed any operation. This is to give all installers a change to cleanup things
     * they did previously, so you need to keep track of changes applied in the installer/downloader themselves.
     *
     * @param  string                $type        one of install/update/uninstall
     * @param  PackageInterface      $package     package instance
     * @param  string                $path        download path
     * @param  PackageInterface      $prevPackage previous package instance in case of an update
     * @return PromiseInterface
     */
    public function cleanup(string $type, PackageInterface $package, string $path, PackageInterface $prevPackage = null): PromiseInterface;
}
