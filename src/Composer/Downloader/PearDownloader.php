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
 * Downloader for pear packages
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 */
use Composer\Package\PackageInterface;

class PearDownloader extends ArchiveDownloader
{
    protected function extract($file, $path)
    {
        // Can throw an UnexpectedValueException
        $archive = new \PharData($file);
        $archive->extractTo($path, null, true);

        $pearInstaller = new PearPackageExtractor();
        $pearInstaller->install($path, $path);

        // do not call for parent cause we don't want post processing of our files
        // parent::extract($file, $path);
    }
}
