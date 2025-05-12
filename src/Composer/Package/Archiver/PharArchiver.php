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

use PharData;

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

            if (!file_exists($target)) {
                $target = $filename . '.' . $format;
                unset($phar);

                if ($format === 'tar') {
                    // create an empty tar file (=512 null bytes) if the tar file is empty and PharData thus did not write it to disk
                    file_put_contents($target, str_repeat("\0", 512));
                } elseif ($format === 'zip') {
                    // create minimal valid ZIP file (Empty Central Directory + End of Central Directory record)
                    $eocd = pack(
                        'VvvvvVVv',
                        0x06054b50,  // End of central directory signature
                        0,           // Number of this disk
                        0,           // Disk where central directory starts
                        0,           // Number of central directory records on this disk
                        0,           // Total number of central directory records
                        0,           // Size of central directory (bytes)
                        0,           // Offset of start of central directory
                        0            // Comment length
                    );

                    file_put_contents($target, $eocd);
                } elseif ($format === 'tar.gz' || $format === 'tar.bz2') {
                    if (!PharData::canCompress(static::$compressFormats[$format])) {
                        throw new \RuntimeException(sprintf('Can not compress to %s format', $format));
                    }
                    if ($format === 'tar.gz' && function_exists('gzcompress')) {
                        file_put_contents($target, gzcompress(str_repeat("\0", 512)));
                    } elseif ($format === 'tar.bz2' && function_exists('bzcompress')) {
                        file_put_contents($target, bzcompress(str_repeat("\0", 512)));
                    }
                }

                return $target;
            }

            if (isset(static::$compressFormats[$format])) {
                // Check can be compressed?
                if (!PharData::canCompress(static::$compressFormats[$format])) {
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
