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

namespace Composer\Package\Archiver;

/**
 * @author Till Klampaeckel <till@php.net>
 * @author Nils Adermann <naderman@naderman.de>
 * @author Matthieu Moquet <matthieu@moquet.net>
 */
class PharArchiver implements ArchiverInterface
{
    /** @var array<string, int> */
    protected static $formats = [
        'zip' => \Phar::ZIP,
        'tar' => \Phar::TAR,
        'tar.gz' => \Phar::TAR,
        'tar.bz2' => \Phar::TAR,
    ];

    /** @var array<string, int> */
    protected static $compressFormats = [
        'tar.gz' => \Phar::GZ,
        'tar.bz2' => \Phar::BZ2,
    ];

    /**
     * @inheritDoc
     */
    public function archive(string $sources, string $target, string $format, array $excludes = [], bool $ignoreFilters = false): string
    {
        $sources = realpath($sources);

        // Phar would otherwise load the file which we don't want
        if (file_exists($target)) {
            unlink($target);
        }

        try {
            $filename = substr($target, 0, strrpos($target, $format) - 1);

            // Check if compress format
            if (isset(static::$compressFormats[$format])) {
                // Current compress format supported base on tar
                $target = $filename . '.tar';
            }

            $phar = new \PharData(
                $target,
                \FilesystemIterator::KEY_AS_PATHNAME | \FilesystemIterator::CURRENT_AS_FILEINFO,
                '',
                static::$formats[$format]
            );
            $files = new ArchivableFilesFinder($sources, $excludes, $ignoreFilters);
            $filesOnly = new ArchivableFilesFilter($files);
            $phar->buildFromIterator($filesOnly, $sources);
            $filesOnly->addEmptyDir($phar, $sources);

            if (isset(static::$compressFormats[$format])) {
                // Check can be compressed?
                if (!$phar->canCompress(static::$compressFormats[$format])) {
                    throw new \RuntimeException(sprintf('Can not compress to %s format', $format));
                }

                // Delete old tar
                unlink($target);

                // Compress the new tar
                $phar->compress(static::$compressFormats[$format]);

                // Make the correct filename
                $target = $filename . '.' . $format;
            }

            return $target;
        } catch (\UnexpectedValueException $e) {
            $message = sprintf(
                "Could not create archive '%s' from '%s': %s",
                $target,
                $sources,
                $e->getMessage()
            );

            throw new \RuntimeException($message, $e->getCode(), $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function supports(string $format, ?string $sourceType): bool
    {
        return isset(static::$formats[$format]);
    }
}
