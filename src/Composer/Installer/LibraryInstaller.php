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

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class LibraryInstaller
{
    protected $dir;

    public function __construct($dir = 'vendor')
    {
        $this->dir = $dir;
    }

    public function install(PackageInterface $package, $downloader)
    {
        $downloader->download($package, $this->dir);
        return array('version' => $package->getVersion());
    }
}