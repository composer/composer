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
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PearDownloader implements DownloaderInterface
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

        $cwd = getcwd();
        chdir($targetPath);

        $tarName = basename($url);

        echo 'Downloading '.$url.' to '.$targetPath.'/'.$tarName.PHP_EOL;
        copy($url, './'.$tarName);

        if (!file_exists($tarName)) {
            throw new \UnexpectedValueException($package->getName().' could not be saved into '.$tarName.', make sure the'
                .' directory is writable and you have internet connectivity.');
        }

        if ($checksum && hash_file('sha1', './'.$tarName) !== $checksum) {
            throw new \UnexpectedValueException('The checksum verification failed for the '.$package->getName().' archive (downloaded from '.$url.'). Installation aborted.');
        }

        echo 'Unpacking archive'.PHP_EOL;
        exec('tar -xzf "'.escapeshellarg($tarName).'"');

        echo 'Cleaning up'.PHP_EOL;
        unlink('./'.$tarName);
        @unlink('./package.sig');
        @unlink('./package.xml');
        if (list($dir) = glob('./'.$package->getName().'-*', GLOB_ONLYDIR)) {
            foreach (array_merge(glob($dir.'/.*'), glob($dir.'/*')) as $file) {
                if (trim(basename($file), '.')) {
                    rename($file, './'.basename($file));
                }
            }
            rmdir($dir);
        }
        chdir($cwd);
    }
}