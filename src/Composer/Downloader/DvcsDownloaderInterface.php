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
 * DVCS Downloader interface.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
interface DvcsDownloaderInterface
{
    /**
     * Checks for unpushed changes to a current branch
     *
     * @param  string      $path package directory
     * @return string|null changes or null
     */
    public function getUnpushedChanges($path);
}
