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

use Composer\Package\PackageInterface;
use Composer\Package\Link;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class Rule2Literals extends Rule
{
    protected $literal1;
    protected $literal2;

    /**
     * @param int                   $literal1
     * @param int                   $literal2
     * @param int                   $reason     A RULE_* constant describing the reason for generating this rule
     * @param Link|PackageInterface $reasonData
     */
    public function __construct($literal1, $literal2, $reason, $reasonData)
    {
        parent::__construct($reason, $reasonData);

        if ($literal1 < $literal2) {
            $this->literal1 = $literal1;
            $this->literal2 = $literal2;
        } else {
            $this->literal1 = $literal2;
            $this->literal2 = $literal1;
        }
    }

    public function getLiterals()
    {
        return array($this->literal1, $this->literal2);
    }

    public function getHash()
    {
        return $this->literal1.','.$this->literal2;
    }

    /**
     * Checks if this rule is equal to another one
     *
     * Ignores whether either of the rules is disabled.
     *
     * @param  Rule $rule The rule to check against
     * @return bool Whether the rules are equal
     */
    public function equals(Rule $rule)
    {
        // specialized fast-case
        if ($rule instanceof self) {
            if ($this->literal1 !== $rule->literal1) {
                return false;
            }

            if ($this->literal2 !== $rule->literal2) {
                return false;
            }

            return true;
        }

        $literals = $rule->getLiterals();
        if (2 != \count($literals)) {
            return false;
        }

        if ($this->literal1 !== $literals[0]) {
            return false;
        }

        if ($this->literal2 !== $literals[1]) {
            return false;
        }

        return true;
    }

    public function isAssertion()
    {
        return false;
    }

    /**
     * Formats a rule as a string of the format (Literal1|Literal2|...)
     *
     * @return string
     */
    public function __toString()
    {
        $result = $this->isDisabled() ? 'disabled(' : '(';

        $result .= $this->literal1 . '|' . $this->literal2 . ')';

        return $result;
    }
}
