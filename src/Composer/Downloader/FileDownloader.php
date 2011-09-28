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
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
abstract class FileDownloader implements DownloaderInterface
{
    /**
     * {@inheritDoc}
     */
    public function distDownload(PackageInterface $package, $path)
    {
        $this->download($package->getDistUrl(), $path, $package->getDistSha1Checksum());
    }

    /**
     * {@inheritDoc}
     */
    public function sourceDownload(PackageInterface $package, $path)
    {
        $this->download($package->getSourceUrl(), $path);
    }

    /**
     * {@inheritDoc}
     */
    public function distUpdate(PackageInterface $initial, PackageInterface $target, $path)
    {
        $fs = new Util\Filesystem();
        $fs->remove($path);
        $this->download($target->getDistUrl(), $path, $target->getDistSha1Checksum());
    }

    /**
     * {@inheritDoc}
     */
    public function sourceUpdate(PackageInterface $initial, PackageInterface $target, $path)
    {
        $fs = new Util\Filesystem();
        $fs->remove($path);
        $this->download($target->getSourceUrl(), $path);
    }

    /**
     * {@inheritDoc}
     */
    public function remove(PackageInterface $package, $path)
    {
        $fs = new Util\Filesystem();
        $fs->remove($path);
    }

    public function download($url, $path, $checksum = null)
    {
        if (!is_dir($path)) {
            if (file_exists($path)) {
                throw new \UnexpectedValueException($path.' exists and is not a directory');
            }
            if (!mkdir($path, 0777, true)) {
                throw new \UnexpectedValueException($path.' does not exist and could not be created');
            }
        }

        $fileName = rtrim($path.'/'.md5(time().rand()).'.'.pathinfo($url, PATHINFO_EXTENSION), '.');

        echo 'Downloading '.$url.' to '.$fileName.PHP_EOL;

        copy($url, $fileName);

        if (!file_exists($fileName)) {
            throw new \UnexpectedValueException($url.' could not be saved to '.$fileName.', make sure the'
                .' directory is writable and you have internet connectivity');
        }

        if ($checksum && hash_file('sha1', $fileName) !== $checksum) {
            throw new \UnexpectedValueException('The checksum verification of the archive failed (downloaded from '.$url.')');
        }

        echo 'Unpacking archive'.PHP_EOL;
        $this->extract($fileName, $path);


        echo 'Cleaning up'.PHP_EOL;
        unlink($fileName);

        // If we have only a one dir inside it suppose to be a package itself
        $contentDir = glob($path . '/*');
        if (1 === count($contentDir)) {
            $contentDir = $contentDir[0];
            foreach (array_merge(glob($contentDir . '/.*'), glob($contentDir . '/*')) as $file) {
                if (trim(basename($file), '.')) {
                    rename($file, $path . '/' . basename($file));
                }
            }
            rmdir($contentDir);
        }
    }

    /**
     * Extract file to directory
     *
     * @param string $file Extracted file
     * @param string $path Directory
     */
    protected abstract function extract($file, $path);
}