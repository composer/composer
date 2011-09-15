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
        if (!is_dir($path)) {
            if (file_exists($path)) {
                throw new \UnexpectedValueException($path.' exists and is not a directory.');
            }
            if (!mkdir($path, 0777, true)) {
                throw new \UnexpectedValueException($path.' does not exist and could not be created.');
            }
        }
        if ($this->clone) {
            system('git clone '.escapeshellarg($url).' -b master '.escapeshellarg($path.'/'.$package->getName()));
        } else {
            system('git archive --format=tar --prefix='.escapeshellarg($package->getName()).' --remote='.escapeshellarg($url).' master | tar -xf -');
        }
    }
}