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
class PearDownloader extends ArchiveDownloader
{
    /**
     * Extract pear package archive to directory.
     * Note: Do not call parent::extract cause it can do some unwanted renames.
     *
     * @param string $file archive file
     * @param string $path target dir
     *
     * @throws \UnexpectedValueException If can not extract downloaded file to path
     */
    protected function extract($file, $path)
    {
        // Can throw an UnexpectedValueException
        $archive = new \PharData($file);
        $archive->extractTo($path, null, true);

        $pearInstaller = new PearPackageExtractor();
        $pearInstaller->install($path, $path);
    }
}
