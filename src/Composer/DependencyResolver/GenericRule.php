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
 */
class GenericRule extends Rule
{
    /** @var list<int> */
    protected $literals;

    /**
     * @param list<int> $literals
     */
    public function __construct(array $literals, $reason, $reasonData)
    {
        parent::__construct($reason, $reasonData);

        // sort all packages ascending by id
        sort($literals);

        $this->literals = $literals;
    }

    /**
     * @return list<int>
     */
    public function getLiterals(): array
    {
        return $this->literals;
    }

    /**
     * @inheritDoc
     */
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
    public function equals(Rule $rule): bool
    {
        return $this->literals === $rule->getLiterals();
    }

    public function isAssertion(): bool
    {
        return 1 === \count($this->literals);
    }

    /**
     * Formats a rule as a string of the format (Literal1|Literal2|...)
     */
    public function __toString(): string
    {
        $result = $this->isDisabled() ? 'disabled(' : '(';

        foreach ($this->literals as $i => $literal) {
            if ($i !== 0) {
                $result .= '|';
            }
            $result .= $literal;
        }

        $result .= ')';

        return $result;
    }
}
