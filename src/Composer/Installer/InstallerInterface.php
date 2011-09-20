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

use Composer\Package\PackageInterface;
use Composer\Downloader\DownloaderInterface;

/**
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
interface InstallerInterface
{
    function isInstalled(PackageInterface $package);
    function install(PackageInterface $package);
}
