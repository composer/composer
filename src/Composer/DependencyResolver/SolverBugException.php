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

namespace Composer\DependencyResolver;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class SolverBugException extends \RuntimeException
{
    public function __construct($message)
    {
        parent::__construct(
            $message."\nThis exception was most likely caused by a bug in Composer.\n".
            "Please report the command you ran, the exact error you received, and your composer.json on https://github.com/composer/composer/issues - thank you!\n"
        );
    }
}
