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

namespace Composer\Package\Loader;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class InvalidPackageException extends \Exception
{
    /** @var string[] */
    private $errors;
    /** @var string[] */
    private $warnings;
    /** @var mixed[] package config */
    private $data;

    /**
     * @param string[] $errors
     * @param string[] $warnings
     * @param mixed[]  $data
     */
    public function __construct(array $errors, array $warnings, array $data)
    {
        $this->errors = $errors;
        $this->warnings = $warnings;
        $this->data = $data;
        parent::__construct("Invalid package information: \n".implode("\n", array_merge($errors, $warnings)));
    }

    /**
     * @return mixed[]
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @return string[]
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * @return string[]
     */
    public function getWarnings()
    {
        return $this->warnings;
    }
}
