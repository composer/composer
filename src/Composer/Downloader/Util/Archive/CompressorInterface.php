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
 * Archive compressor
 * 
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 */
interface CompressorInterface
{
    /**
     * Get type of archive.
     *
     * @return string Usually an archive extension, like zip
     */
    public function getArchiveType();

    /**
     * Compress the given directory in targetFile
     *
     * @param string $dir Directory for compression
     * @param string $targetFile File to compress to
     *
     * @throws \RuntimeException
     */
    public function compressDir($dir, $targetFile);
}
