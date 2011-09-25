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
 * Downloader for PEAR packages
 *
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 */
class PearDownloader extends GzipTarDownloader
{
    protected function extract($file, $path)
    {
        parent::extract($file, $path);
        @unlink($path . '/package.sig');
        @unlink($path . '/package.xml');
    }
}