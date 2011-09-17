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

use Composer\Downloader\DownloaderInterface;
use Composer\Package\PackageInterface;

/**
 * Package Installer
 * 
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 */ 
interface InstallerInterface
{
    /**
     * Install package
     *
     * @param PackageInterface    $package
     * @param DownloaderInterface $downloader
     * @param string              $type
     */
    function install(PackageInterface $package, DownloaderInterface $downloader, $type);
}
