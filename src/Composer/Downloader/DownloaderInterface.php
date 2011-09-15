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
 * Package Downloader
 * 
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 */ 
interface DownloaderInterface 
{
    /**
     * Download package
     *
     * @param PackageInterface $package Downloaded package
     * @param string           $path Download to
     * @param string           $url Download from
     * @param string|null      $checksum Package checksum
     *
     * @throws \UnexpectedValueException
     */
    public function download(PackageInterface $package, $path, $url, $checksum = null);
}
