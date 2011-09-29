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
 * Downloader for phar files
 *
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 */
class PharDownloader extends FileDownloader
{
    /**
     * {@inheritDoc}
     */
    protected function extract($file, $path)
    {
        // Can throw an UnexpectedValueException
        $archive = new \Phar($file);
        $archive->extractTo($path);
    }
}
