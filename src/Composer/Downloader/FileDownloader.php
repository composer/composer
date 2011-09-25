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
 * Base downloader for file packages
 * 
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 */ 
abstract class FileDownloader implements DownloaderInterface
{
    public function download(PackageInterface $package, $path, $url, $checksum = null)
    {
        $targetPath = $path . "/" . $package->getName();
        if (!is_dir($targetPath)) {
            if (file_exists($targetPath)) {
                throw new \UnexpectedValueException($targetPath.' exists and is not a directory.');
            }
            if (!mkdir($targetPath, 0777, true)) {
                throw new \UnexpectedValueException($targetPath.' does not exist and could not be created.');
            }
        }

        $fileName = $targetPath.'/'.md5(time().rand());

        echo 'Downloading '.$url.' to '.$fileName.PHP_EOL;

        copy($url, $fileName);

        if (!file_exists($fileName)) {
            throw new \UnexpectedValueException($path.' could not be saved into '.$fileName.', make sure the'
                .' directory is writable and you have internet connectivity.');
        }

        if ($checksum && hash_file('sha1', $fileName) !== $checksum) {
            throw new \UnexpectedValueException('The checksum verification failed for the '.$package->getName().' archive (downloaded from '.$url.'). Installation aborted.');
        }

        echo 'Unpacking archive'.PHP_EOL;
        $this->extract($fileName, $targetPath);

        // TODO: Need to remove this dirty hack or make it less dirty ;)
        if (false !== strpos($url, '//github.com/')) {
            $contentDir = glob($targetPath . '/*');
            if (1 === count($contentDir)) {
                $contentDir = $contentDir[0];
                foreach (array_merge(glob($contentDir . '/.*'), glob($contentDir . '/*')) as $file) {
                    if (trim(basename($file), '.')) {
                        rename($file, $targetPath . '/' . basename($file));
                    }
                }
                rmdir($contentDir);
            }
        }

        echo 'Cleaning up'.PHP_EOL;
        unlink($fileName);
    }

    /**
     * Extract file to directory
     *
     * @param string $file Extracted file
     * @param string $path Directory
     */
    protected abstract function extract($file, $path);

}
