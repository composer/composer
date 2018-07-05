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
class GenericRule extends Rule
{
    protected $literals;

    /**
     * @param array                 $literals
     * @param int                   $reason     A RULE_* constant describing the reason for generating this rule
     * @param Link|PackageInterface $reasonData
     * @param array                 $job        The job this rule was created from
     */
    public function __construct(array $literals, $reason, $reasonData, $job = null)
    {
        parent::__construct($reason, $reasonData, $job);

        // sort all packages ascending by id
        sort($literals);

        $this->literals = $literals;
    }

    public function getLiterals()
    {
        return $this->literals;
    }

    public function getHash()
    {
        $data = unpack('ihash', md5(implode(',', $this->literals), true));

        return $data['hash'];
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
        return $this->literals === $rule->getLiterals();
    }

    public function isAssertion()
    {
        return 1 === count($this->literals);
    }

    /**
     * Formats a rule as a string of the format (Literal1|Literal2|...)
     *
     * @return string
     */
    public function __toString()
    {
        $result = $this->isDisabled() ? 'disabled(' : '(';

        foreach ($this->literals as $i => $literal) {
            if ($i != 0) {
                $result .= '|';
            }
            $result .= $literal;
        }

        $result .= ')';

        return $result;
    }
}
