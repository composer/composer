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
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
interface DownloaderInterface
{
    function download(PackageInterface $package, $path, $url, $checksum = null);
    function isDownloaded(PackageInterface $package, $path);
}
