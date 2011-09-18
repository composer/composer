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
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class GitDownloader implements DownloaderInterface
{
    protected $clone;

    public function __construct($clone = true)
    {
        $this->clone = $clone;
    }

    public function download(PackageInterface $package, $path, $url, $checksum = null)
    {
        if ($this->clone) {
            system('git clone '.escapeshellarg($url).' -b master '.escapeshellarg($path.'/'.$package->getName()));
        } else {
            system('git archive --format=tar --prefix='.escapeshellarg($package->getName()).' --remote='.escapeshellarg($url).' master | tar -xf -');
        }
    }

    public function isDownloaded(PackageInterface $package, $path)
    {
        $targetPath = $path . '/' . $package->getName();

        return is_dir($targetPath);
    }
}
