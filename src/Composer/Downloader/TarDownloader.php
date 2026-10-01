<?php declare(strict_types=1);

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
use Composer\Util\Platform;
use React\Promise\PromiseInterface;

/**
 * Downloader for tar files: tar, tar.gz or tar.bz2
 *
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 */
class TarDownloader extends ArchiveDownloader
{
    /**
     * @inheritDoc
     *
     * @throws \RuntimeException
     */
    protected function extract(PackageInterface $package, string $file, string $path): PromiseInterface
    {
        // Guard against unsafe Phar/PharData metadata handling on PHP < 8.0
        Platform::assertPharMetadataSafe();

        // Can throw an UnexpectedValueException
        $archive = new \PharData($file);
        $archive->extractTo($path, null, true);

        return \React\Promise\resolve(null);
    }
}
