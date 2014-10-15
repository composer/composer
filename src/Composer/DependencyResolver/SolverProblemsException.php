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

        parent::__construct($this->createMessage(), 2);
    }

    protected function createMessage()
    {
        $text = "\n";
        foreach ($this->problems as $i => $problem) {
            $text .= "  Problem ".($i+1).$problem->getPrettyString($this->installedMap)."\n";
        }

        if (strpos($text, 'could not be found') || strpos($text, 'no matching package found')) {
            $text .= "\nPotential causes:\n - A typo in the package name\n - The package is not available in a stable-enough version according to your minimum-stability setting\n   see <https://groups.google.com/d/topic/composer-dev/_g3ASeIFlrc/discussion> for more details.\n\nRead <http://getcomposer.org/doc/articles/troubleshooting.md> for further common problems.";
        }

        return $text;
    }

    public function getProblems()
    {
        return $this->problems;
    }
}
