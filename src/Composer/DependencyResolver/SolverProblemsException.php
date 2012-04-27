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
class SolverProblemsException extends \RuntimeException
{
    protected $problems;

    public function __construct(array $problems)
    {
        $this->problems = $problems;

        parent::__construct($this->createMessage());
    }

    protected function createMessage()
    {
        $messages = array();

        foreach ($this->problems as $problem) {
            $messages[] = (string) $problem;
        }

        return "\n\tProblems:\n\t\t- ".implode("\n\t\t- ", $messages);
    }

    public function getProblems()
    {
        return $this->problems;
    }
}
