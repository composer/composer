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

namespace Composer\Package\Archiver;

/**
 * @author Till Klampaeckel <till@php.net>
 * @author Nils Adermann <naderman@naderman.de>
 * @author Matthieu Moquet <matthieu@moquet.net>
 */
class PharArchiver implements ArchiverInterface
{
    protected static $formats = array(
        'zip' => \Phar::ZIP,
        'tar' => \Phar::TAR,
    );

    protected static $tarCompressionFormats = array(
        'tar.gz' => \Phar::GZ,
        'tar.bz2' => \Phar::BZ2,
    );

    /**
     * {@inheritdoc}
     */
    public function archive($sources, $target, $format, array $excludes = array())
    {
        $sources = realpath($sources);
        $tarCompressionFormat = false;
        $archiveFile = $target;

        // Support compressed tar.[gz|bz2] files by first generating tar and then compressing it.
        if (isset(static::$tarCompressionFormats[$format]))
        {
            $tarCompressionFormat = $format;
            $format = 'tar';
            $archiveFile = substr($target, 0, - strlen($tarCompressionFormat)) . $format;
        }

        // Phar archive would otherwise load the file which we don't want
        if (file_exists($archiveFile)) {
            unlink($archiveFile);
        }

        try {
            $phar = new \PharData($archiveFile, null, null, static::$formats[$format]);
            $files = new ArchivableFilesFinder($sources, $excludes);
            $phar->buildFromIterator($files, $sources);

            // Generate compressed tar file and unlink temporary archive
            if ($tarCompressionFormat)
            {
                $phar->compress(static::$tarCompressionFormats[$tarCompressionFormat], $tarCompressionFormat);
                unlink($archiveFile);
            }
            // if zip attempt to compress the file if possible with gz
            else if ($format === 'zip' && extension_loaded('zlib'))
            {
                $phar->compressFiles(\Phar::GZ);
            }

            return $target;
        } catch (\UnexpectedValueException $e) {
            $message = sprintf("Could not create archive '%s' from '%s': %s",
                $target,
                $sources,
                $e->getMessage()
            );

            throw new \RuntimeException($message, $e->getCode(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function supports($format, $sourceType)
    {
        return isset(static::$formats[$format]) || isset(static::$tarCompressionFormats[$format]);
    }
}
