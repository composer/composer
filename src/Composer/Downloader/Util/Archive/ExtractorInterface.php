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

namespace Composer\Downloader\Util\Archive;

/**
 * Archive extractor
 * 
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 */
interface ExtractorInterface extends ArchiveSupportInterface
{
    /**
     * Extract archive to directory
     *
     * @param string $file
     * @param string $targetDir
     *
     * @throws UnsupportedArchiveException If file is not supported by this extractor
     * @throws \RuntimeException For other unexpected problems
     */
    public function extractTo($file, $targetDir);
}
