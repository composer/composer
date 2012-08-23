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

use Composer\Package\PackageInterface;

/**
 * @author Till Klampaeckel <till@php.net>
 * @author Matthieu Moquet <matthieu@moquet.net>
 */
interface ArchiverInterface
{
    /**
     * Create an archive from the sources.
     *
     * @param string $source The sources directory
     * @param string $target The target file
     */
    public function archive($sources, $target);

    /**
     * Format supported by the archiver.
     *
     * @param string $format The format to support
     *
     * @return boolean true if the format is supported by the archiver
     */
    public function supports($format);
}
