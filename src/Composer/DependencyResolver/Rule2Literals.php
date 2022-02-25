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

namespace Composer\DependencyResolver;

/**
 * @author Nils Adermann <naderman@naderman.de>
 * @phpstan-import-type ReasonData from Rule
 */
class Rule2Literals extends Rule
{
    /** @var int */
    protected $literal1;
    /** @var int */
    protected $literal2;

    /**
     * @param int $literal1
     * @param int $literal2
     * @param Rule::RULE_* $reason A RULE_* constant
     * @param mixed $reasonData
     *
     * @phpstan-param ReasonData $reasonData
     */
    public function __construct(int $literal1, int $literal2, $reason, $reasonData)
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

    /** @return int[] */
    public function getLiterals(): array
    {
        return array($this->literal1, $this->literal2);
    }

    /**
     * @inheritDoc
     */
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
    public function equals(Rule $rule): bool
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

    /** @return false */
    public function isAssertion(): bool
    {
        return false;
    }

    /**
     * Formats a rule as a string of the format (Literal1|Literal2|...)
     *
     * @return string
     */
    public function __toString(): string
    {
        $result = $this->isDisabled() ? 'disabled(' : '(';

        $result .= $this->literal1 . '|' . $this->literal2 . ')';

        return $result;
    }
}
