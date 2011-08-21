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

    public function install(PackageInterface $package, $downloader, $type)
    {
        if ($type === 'dist') {
            $downloader->download($package, $this->dir, $package->getDistUrl(), $package->getDistSha1Checksum());
        } elseif ($type === 'source') {
            $downloader->download($package, $this->dir, $package->getSourceUrl());
        } else {
            throw new \InvalidArgumentException('Type must be one of (dist, source), '.$type.' given.');
        }
        return true;
    }
}