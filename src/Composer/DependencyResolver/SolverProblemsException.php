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
    protected $installedMap;

    public function __construct(array $problems, array $installedMap)
    {
        $this->problems = $problems;
        $this->installedMap = $installedMap;

        parent::__construct($this->createMessage());
    }

    protected function createMessage()
    {
        $text = "\n";
        foreach ($this->problems as $i => $problem) {
            $text .= "  Problem ".($i+1).$problem->getPrettyString($this->installedMap)."\n";
        }

        return $text;
    }

    public function getProblems()
    {
        return $this->problems;
    }
}
