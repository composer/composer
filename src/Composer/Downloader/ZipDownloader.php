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

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ZipDownloader implements DownloaderInterface
{
    /**
     * {@inheritDoc}
     */
    public function download($path, $url, $checksum = null, $useSource = false)
    {
        $this->downloadTo($url, $path, $checksum);
    }

    /**
     * {@inheritDoc}
     */
    public function update($path, $url, $checksum = null, $useSource = false)
    {
        // TODO rm old dir
        $this->downloadTo($url, $path, $checksum);
    }

    /**
     * {@inheritDoc}
     */
    public function remove($path, $useSource = false)
    {
        echo 'rm -rf '.$path; // TODO
    }

    private function downloadTo($url, $targetPath, $checksum = null)
    {
        if (!class_exists('ZipArchive')) {
            throw new \UnexpectedValueException('You need the zip extension enabled to use the ZipDownloader');
        }

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
            throw new \UnexpectedValueException($targetPath.' could not be saved into '.$zipName.', make sure the'
                .' directory is writable and you have internet connectivity.');
        }

        if ($checksum && hash_file('sha1', $zipName) !== $checksum) {
            throw new \UnexpectedValueException('The checksum verification failed for the '.basename($path).' archive (downloaded from '.$url.'). Installation aborted.');
        }

        $zipArchive = new \ZipArchive();

        echo 'Unpacking archive'.PHP_EOL;
        if (true === ($retval = $zipArchive->open($zipName))) {
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
