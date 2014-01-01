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
 * Zip archive downloader.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Abdellatif Ait boudad <a.aitboudad@gmail.com>
 */
class ZipDownloader extends ArchiveDownloader
{
    /**
     * {@inheritDoc}
     */
    protected function extract($file, $path)
    {
        try {
            $zipArchive = new \PharData($file);

            if (!$zipArchive->isFileFormat(\Phar::ZIP)) {
                throw new \UnexpectedValueException(sprintf("'%s' is not a zip archive.", $file));
            }

            $zipArchive->extractTo($path, null, true);
        } catch (\Exception $exception) {
            throw new \UnexpectedValueException(sprintf('Failed to extract zip archive %s to %s. Reason: %s', $file, $path, $exception->getMessage()), 0, $exception);
        }
    }
}
