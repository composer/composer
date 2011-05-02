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
class ZipDownloader
{
    public function download(PackageInterface $package, $path)
    {
        $tmpName = tempnam(sys_get_temp_dir(), '');
        $this->downloadFile($package->getSourceUrl(), $tmpName);

        if (!file_exists($tmpName)) {
            throw new \UnexpectedValueException($tmpName.' could not be created.');
        }

        $zipArchive = new ZipArchive();

        if($zipArchive->open($tmpName) !== TRUE) {
            $zipArchive->extractTo($path.'/'.$package->getName());
            $zipArchive->close();
        }
        else {
            throw new \UnexpectedValueException($tmpName.'is not a valid zip archive');
        }
    }

    protected function downloadFile ($url, $path)
    {
        $file = fopen ($url, "rb");
        if ($file) {
            $newf = fopen ($path, "wb");
            if ($newf) {
                while(!feof($file)) {
                    fwrite($newf, fread($file, 1024 * 8 ), 1024 * 8 );
                }
            }
        }
        if ($file) {
            fclose($file);
        }
        if ($newf) {
            fclose($newf);
        }
    }
}