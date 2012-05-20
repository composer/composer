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
class RuleWatchNode
{
    public $watch1;
    public $watch2;

    protected $rule;

    public function __construct($rule)
    {
        $this->rule = $rule;

        $literals = $rule->getLiterals();

        $this->watch1 = (count($literals) > 0) ? $literals[0] : 0;
        $this->watch2 = (count($literals) > 1) ? $literals[1] : 0;
    }

    /**
     * Put watch2 on rule's literal with highest level
     */
    public function watch2OnHighest($decisionMap)
    {
        $literals = $this->rule->getLiterals();

        // if there are only 2 elements, both are being watched anyway
        if ($literals < 3) {
            return;
        }

        $watchLevel = 0;

        foreach ($literals as $literal) {
            $level = abs($decisionMap[abs($literal)]);

            if ($level > $watchLevel) {
                $this->rule->watch2 = $literal;
                $watchLevel = $level;
            }
        }
    }

    public function getRule()
    {
        return $this->rule;
    }

    public function getOtherWatch($literal)
    {
        if ($this->watch1 == $literal) {
            return $this->watch2;
        } else {
            return $this->watch1;
        }
    }

    public function moveWatch($from, $to)
    {
        if ($this->watch1 == $from) {
            $this->watch1 = $to;
        } else {
            $this->watch2 = $to;
        }
    }
}
