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

    protected static $compressionFormats = array(
        'tar.gz' => \Phar::GZ,
        'tar.bz2' => \Phar::BZ2,
    );

    /**
     * {@inheritdoc}
     */
    public function archive($sources, $target, $format, array $excludes = array())
    {
        $sources = realpath($sources);

        // Phar would otherwise load the file which we don't want
        if (file_exists($target)) {
            unlink($target);
        }

        // Support compressed variants of tar files
        $compressionFormat = false;
        if (isset(static::$compressionFormats[$format]))
        {
            $compressionFormat = $format;
            $format = 'tar';
        }

        try {
            $phar = new \PharData($target, null, null, static::$formats[$format]);
            $files = new ArchivableFilesFinder($sources, $excludes);
            $phar->buildFromIterator($files, $sources);

            if ($compressionFormat)
                $phar->compress(static::$compressionFormats[$compressionFormat], $compressionFormat);

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
        return isset(static::$formats[$format]) || isset(static::$compressionFormats[$format]);
    }
}
