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
 */
class PearDownloader extends AbstractDownloader
{
    public function download(PackageInterface $package, $path)
    {
        $path = $path . "/" . $package->getName();
        if (!is_dir($path)) {
            if (file_exists($path)) {
                throw new \UnexpectedValueException($path.' exists and is not a directory.');
            }
            if (!mkdir($path, 0777, true)) {
                throw new \UnexpectedValueException($path.' does not exist and could not be created.');
            }
        }

        $tmpName = tempnam(sys_get_temp_dir(), '');
        copy($package->getSourceUrl(), $tmpName);

        if (!file_exists($tmpName)) {
            throw new \UnexpectedValueException($package->getName().' could not be saved into '.$tmpName.', make sure the'
                .' directory is writable and you have internet connectivity.');
        }

        $cwd = getcwd();
        chdir($path);
        system('tar xzf '.escapeshellarg($tmpName));
        chdir($cwd);
    }
}