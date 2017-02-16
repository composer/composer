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
 * Wrapper around a Rule which keeps track of the two literals it watches
 *
 * Used by RuleWatchGraph to store rules in two RuleWatchChains.
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class RuleWatchNode
{
    public $watch1;
    public $watch2;

    protected $rule;

    /**
     * Creates a new node watching the first and second literals of the rule.
     *
     * @param Rule $rule The rule to wrap
     */
    public function __construct($rule)
    {
        $this->rule = $rule;

        $literals = $rule->getLiterals();

        $this->watch1 = count($literals) > 0 ? $literals[0] : 0;
        $this->watch2 = count($literals) > 1 ? $literals[1] : 0;
    }

    /**
     * Places the second watch on the rule's literal, decided at the highest level
     *
     * Useful for learned rules where the literal for the highest rule is most
     * likely to quickly lead to further decisions.
     *
     * @param Decisions $decisions The decisions made so far by the solver
     */
    public function watch2OnHighest(Decisions $decisions)
    {
        $literals = $this->rule->getLiterals();

        // if there are only 2 elements, both are being watched anyway
        if (count($literals) < 3) {
            return;
        }

        $watchLevel = 0;

        foreach ($literals as $literal) {
            $level = $decisions->decisionLevel($literal);

            if ($level > $watchLevel) {
                $this->watch2 = $literal;
                $watchLevel = $level;
            }
        }
    }

    /**
     * Returns the rule this node wraps
     *
     * @return Rule
     */
    public function getRule()
    {
        return $this->rule;
    }

    /**
     * Given one watched literal, this method returns the other watched literal
     *
     * @param  int $literal The watched literal that should not be returned
     * @return int A literal
     */
    public function getOtherWatch($literal)
    {
        if ($this->watch1 == $literal) {
            return $this->watch2;
        } else {
            return $this->watch1;
        }
    }

    /**
     * Moves a watch from one literal to another
     *
     * @param int $from The previously watched literal
     * @param int $to   The literal to be watched now
     */
    public function moveWatch($from, $to)
    {
        if ($this->watch1 == $from) {
            $this->watch1 = $to;
        } else {
            $this->watch2 = $to;
        }
    }
}
