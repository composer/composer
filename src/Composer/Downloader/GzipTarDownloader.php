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
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 */
class GzipTarDownloader extends FileDownloader
{
    protected function extract($file, $path)
    {
        exec('tar -xzf "'.escapeshellarg($file).'"');
    }
}