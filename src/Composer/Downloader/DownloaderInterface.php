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

namespace Composer\Downloader;

use Composer\Package\PackageInterface;

/**
 * Downloader interface.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
interface DownloaderInterface
{
    /**
     * Downloads specific package into specific folder.
     *
     * @param   PackageInterface    $package    package instance
     * @param   string              $path       download path
     * @param   string              $url        download url
     * @param   string              $checksum   package checksum (for dists)
     * @param   Boolean             $useSource  download as source
     */
    function download(PackageInterface $package, $path, $url, $checksum = null, $useSource = false);

    /**
     * Updates specific package in specific folder from initial to target version.
     *
     * @param   PackageInterface    $initial    initial package
     * @param   PackageInterface    $target     updated package
     * @param   string              $path       download path
     * @param   Boolean             $useSource  download as source
     */
    function update(PackageInterface $initial, PackageInterface $target, $path, $useSource = false);

    /**
     * Removes specific package from specific folder.
     *
     * @param   PackageInterface    $package    package instance
     * @param   string              $path       download path
     * @param   Boolean             $useSource  download as source
     */
    function remove(PackageInterface $package, $path, $useSource = false);
}
