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
     * @param   string  $path       download path
     * @param   string  $url        download url
     * @param   string  $checksum   package checksum (for dists)
     * @param   Boolean $useSource  download as source
     */
    function download($path, $url, $checksum = null, $useSource = false);

    /**
     * Updates specific package in specific folder from initial to target version.
     *
     * @param   string  $path       download path
     * @param   string  $url        download url
     * @param   string  $checksum   package checksum (for dists)
     * @param   Boolean $useSource  download as source
     */
    function update($path, $url, $checksum = null, $useSource = false);

    /**
     * Removes specific package from specific folder.
     *
     * @param   string  $path       download path
     * @param   Boolean $useSource  download as source
     */
    function remove($path, $useSource = false);
}
