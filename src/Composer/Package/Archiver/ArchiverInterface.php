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
 * @author Matthieu Moquet <matthieu@moquet.net>
 * @author Nils Adermann <naderman@naderman.de>
 */
interface ArchiverInterface
{
    /**
     * Create an archive from the sources.
     *
     * @param string $sources  The sources directory
     * @param string $target   The target file
     * @param string $format   The format used for archive
     * @param array  $excludes A list of patterns for files to exclude
     *
     * @return string The path to the written archive file
     */
    public function archive($sources, $target, $format, array $excludes = array());

    /**
     * Format supported by the archiver.
     *
     * @param string $format     The archive format
     * @param string $sourceType The source type (git, svn, hg, etc.)
     *
     * @return bool true if the format is supported by the archiver
     */
    public function supports($format, $sourceType);
}
