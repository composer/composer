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

use Composer\Util\ProcessExecutor;

/**
 * VCS archivers are optimized for a specific source type.
 *
 * @author Till Klampaeckel <till@php.net>
 * @author Matthieu Moquet <matthieu@moquet.net>
 */
abstract class VcsArchiver implements ArchiverInterface
{
    protected $process;
    protected $sourceRef;
    protected $format;

    public function __construct($process = null)
    {
        $this->process = $process ?: new ProcessExecutor();
    }

    public function getSourceRef()
    {
        return $this->sourceRef;
    }

    public function setSourceRef($sourceRef)
    {
        $this->sourceRef = $sourceRef;
    }

    public function getFormat()
    {
        return $this->format;
    }

    public function setFormat($format)
    {
        $this->format = $format;
    }

    /**
     * Get the source type supported by the archiver.
     *
     * @return string The source type of the archiver
     */
    abstract public function getSourceType();
}