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
    protected $problemFormatter;

    public function __construct(array $problems, ProblemFormatter $problemFormatter)
    {
        $this->problems = $problems;
        $this->problemFormatter = $problemFormatter;

        parent::__construct($this->createMessage(), 2);
    }

    protected function createMessage()
    {
        $text = "\n";
        $advices = array();

        foreach ($this->problems as $key => $problem) {
            $formattedProblem = $this->problemFormatter->format($problem, $key);

            $text .= $formattedProblem['text'];
            $advices = array_merge($advices, $formattedProblem['advices']);
        }

        foreach ($advices as $advice) {
            $text .= "\n$advice";
        }

        return $text;
    }

    public function getProblems()
    {
        return $this->problems;
    }
}
