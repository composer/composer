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

namespace Composer\Json;

use Exception;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class JsonValidationException extends Exception
{
    /**
     * @var string[]
     */
    protected $errors;

    /**
     * @param string   $message
     * @param string[] $errors
     */
    public function __construct(string $message, array $errors = [], Exception $previous = null)
    {
        $this->errors = $errors;
        parent::__construct((string) $message, 0, $previous);
    }

    /**
     * @return string[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
