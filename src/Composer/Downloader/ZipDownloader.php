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
class ZipDownloader implements DownloaderInterface
{
    public function download(PackageInterface $package, $path, $url, $checksum = null)
    {
        if (!class_exists('ZipArchive')) {
            throw new \UnexpectedValueException('You need the zip extension enabled to use the ZipDownloader');
        }

        $targetPath = $path . "/" . $package->getName();
        if (!is_dir($targetPath)) {
            if (file_exists($targetPath)) {
                throw new \UnexpectedValueException($targetPath.' exists and is not a directory.');
            }
            if (!mkdir($targetPath, 0777, true)) {
                throw new \UnexpectedValueException($targetPath.' does not exist and could not be created.');
            }
        }

        $zipName = $targetPath.'/'.basename($url, '.zip').'.zip';
        echo 'Downloading '.$url.' to '.$zipName.PHP_EOL;
        copy($url, $zipName);

        if (!file_exists($zipName)) {
            throw new \UnexpectedValueException($path.' could not be saved into '.$zipName.', make sure the'
                .' directory is writable and you have internet connectivity.');
        }

        if ($checksum && hash_file('sha1', $zipName) !== $checksum) {
            throw new \UnexpectedValueException('The checksum verification failed for the '.$package->getName().' archive (downloaded from '.$url.'). Installation aborted.');
        }

        $zipArchive = new \ZipArchive();

        echo 'Unpacking archive'.PHP_EOL;
        if (true === ($retval = $zipArchive->open($zipName))) {
            $targetPath = $path.'/'.$package->getName();
            $zipArchive->extractTo($targetPath);
            $zipArchive->close();
            echo 'Cleaning up'.PHP_EOL;
            unlink($zipName);
            if (false !== strpos($url, '//github.com/')) {
                $contentDir = glob($targetPath.'/*');
                if (1 === count($contentDir)) {
                    $contentDir = $contentDir[0];
                    foreach (array_merge(glob($contentDir.'/.*'), glob($contentDir.'/*')) as $file) {
                        if (trim(basename($file), '.')) {
                            rename($file, $targetPath.'/'.basename($file));
                        }
                    }
                    rmdir($contentDir);
                }
            }
        } else {
            throw new \UnexpectedValueException($zipName.' is not a valid zip archive, got error code '.$retval);
        }
    }
}