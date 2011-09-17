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
use Composer\Composer;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class LibraryInstaller implements InstallerInterface
{
    private $dir;
    private $composer;

    public function __construct($dir = 'vendor')
    {
        $this->dir = $dir;
    }

    public function setComposer(Composer $composer)
    {
        $this->composer = $composer;
    }

    public function install(PackageInterface $package)
    {
        if ($package->getDistType()) {

            $this->composer->getDownloader($package->getDistType())->download(
                $package, $this->dir, $package->getDistUrl(), $package->getDistSha1Checksum()
            );

        } elseif ($package->getSourceType()) {

            $this->composer->getDownloader($package->getSourceType())->download(
                $package, $this->dir, $package->getSourceUrl()
            );

        } else {
            throw new \InvalidArgumentException(
                'Type must be one of (dist, source), '.$type.' given.'
            );
        }

        return true;
    }

    public function isInstalled(PackageInterface $package)
    {
        // TODO: implement installation check
    }

    public function update(PackageInterface $package)
    {
        // TODO: implement package update
    }

    public function remove(PackageInterface $package)
    {
        // TODO: implement package removal
    }
}
