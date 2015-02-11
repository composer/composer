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

namespace Composer\Json;

use Exception;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class JsonValidationException extends Exception
{
    protected $errors;

    public function __construct($message, $errors = array(), Exception $previous = null)
    {
        $this->errors = $errors;
        parent::__construct($message, 0, $previous);
    }

    public function getErrors()
    {
        return $this->errors;
    }
}
