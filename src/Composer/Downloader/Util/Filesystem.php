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
    public function removeDirectory($directory)
    {
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            system(sprintf('rmdir /S /Q %s', escapeshellarg(realpath($directory))));
        } else {
            system(sprintf('rm -rf %s', escapeshellarg($directory)));
        }
    }

    public function ensureDirectoryExists($directory)
    {
        if (!is_dir($directory)) {
            if (file_exists($directory)) {
                throw new \RuntimeException(
                    $directory.' exists and is not a directory.'
                );
            }
            if (!mkdir($directory, 0777, true)) {
                throw new \RuntimeException(
                    $directory.' does not exist and could not be created.'
                );
            }
        }
    }
}
