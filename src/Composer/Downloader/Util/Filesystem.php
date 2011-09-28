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

namespace Composer\Downloader\Util;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Filesystem
{
    public function remove($directory)
    {
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            system(sprintf('rmdir /S /Q %s', escapeshellarg(realpath($directory))));
        } else {
            system(sprintf('rm -rf %s', escapeshellarg($directory)));
        }
    }
}
