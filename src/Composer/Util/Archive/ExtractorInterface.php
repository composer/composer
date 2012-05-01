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

namespace Composer\Util\Archive;

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
     * @param string $file      Archive file
     * @param string $targetDir Target directory
     *
     * @throws \RuntimeException
     */
    public function extractTo($file, $targetDir);
}
