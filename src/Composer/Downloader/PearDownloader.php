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
class PearDownloader
{
    public function download(PackageInterface $package, $path)
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

        $source = $package->getSourceUrl();
        $tarName = basename($source);

        echo 'Downloading '.$source.' to '.$targetPath.'/'.$tarName.PHP_EOL;
        copy($package->getSourceUrl(), './'.$tarName);

        if (!file_exists($tarName)) {
            throw new \UnexpectedValueException($package->getName().' could not be saved into '.$tarName.', make sure the'
                .' directory is writable and you have internet connectivity.');
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