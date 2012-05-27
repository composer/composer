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
 * Downloader for phar files
 *
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 */
class PharDownloader extends ArchiveDownloader
{
    /**
     * {@inheritDoc}
     */
    protected function extract($file, $path)
    {
        // Can throw an UnexpectedValueException
        $archive = new \Phar($file);
        $archive->extractTo($path, null, true);
        /* TODO: handle openssl signed phars
         * https://github.com/composer/composer/pull/33#issuecomment-2250768
         * https://github.com/koto/phar-util
         * http://blog.kotowicz.net/2010/08/hardening-php-how-to-securely-include.html
         */
    }
}
