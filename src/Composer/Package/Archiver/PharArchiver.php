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

use Composer\Package\BasePackage;
use Composer\Package\PackageInterface;

/**
 * @author Till Klampaeckel <till@php.net>
 * @author Matthieu Moquet <matthieu@moquet.net>
 */
class PharArchiver implements ArchiverInterface
{
    static protected $formats = array(
        'zip' => \Phar::ZIP,
        'tar' => \Phar::TAR,
    );

    /**
     * {@inheritdoc}
     */
    public function archive($sources, $target, $format, $sourceRef = null)
    {
        $this->createPharArchive($sources, $target, static::$formats[$format]);
    }

    /**
     * {@inheritdoc}
     */
    public function supports($format, $sourceType)
    {
        return isset(static::$formats[$format]);
    }

    /**
     * Create a PHAR archive.
     *
     * @param string $sources Path of the directory to archive
     * @param string $target  Path of the file archive to create
     * @param int    $format  Format of the archive
     */
    protected function createPharArchive($sources, $target, $format)
    {
        try {
            $phar = new \PharData($target, null, null, $format);
            $phar->buildFromDirectory($sources);
        } catch (\UnexpectedValueException $e) {
            $message = sprintf("Could not create archive '%s' from '%s': %s",
                $target,
                $sources,
                $e->getMessage()
            );

            throw new \RuntimeException($message, $e->getCode(), $e);
        }
    }
}
