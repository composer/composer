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
class PearDownloader extends FileDownloader
{
    protected function extract($file, $path)
    {
        $oldDir = getcwd();
        chdir(dirname($file));
        system(sprintf('tar -zxf %s', escapeshellarg(basename($file))));
        chdir($oldDir);
        @unlink($path . '/package.sig');
        @unlink($path . '/package.xml');
    }
}
