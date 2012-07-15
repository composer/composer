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

namespace Composer\Downloader\Util\Archive;

/**
 * Exception for unsupported archive files
 * 
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 */
class UnsupportedArchiveException extends \UnexpectedValueException
{
    private $file;
    private $type;

    /**
     * Constructor
     *
     * @param string          $file Name of unsupported file
     * @param string          $type Archive type
     * @param \Exception|null $previous Previous exception
     */
    public function __construct($file, $type, \Exception $previous = null)
    {
        $this->file = $file;
        $this->type = $type;
        parent::__construct(sprintf("Unsupported '%s' archive file '%s'", $type, $file), 0, $previous);
    }

    /**
     * Get file
     *
     * @return string
     */
    public function getFile()
    {
        return $this->file;
    }

    /**
     * Get type
     *
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }
}
