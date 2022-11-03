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

namespace Composer\Package\Loader;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class InvalidPackageException extends \Exception
{
    /** @var list<string> */
    private $errors;
    /** @var list<string> */
    private $warnings;
    /** @var mixed[] package config */
    private $data;

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
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
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @return list<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
