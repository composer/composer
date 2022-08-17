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
 *
 * MultiConflictRule([A, B, C]) acts as Rule([-A, -B]), Rule([-A, -C]), Rule([-B, -C])
 */
class MultiConflictRule extends Rule
{
    /** @var list<int> */
    protected $literals;

    /**
     * @param list<int> $literals
     */
    public function __construct(array $literals, $reason, $reasonData)
    {
        parent::__construct($reason, $reasonData);

        if (\count($literals) < 3) {
            throw new \RuntimeException("multi conflict rule requires at least 3 literals");
        }

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
        $data = unpack('ihash', md5('c:'.implode(',', $this->literals), true));

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
        if ($rule instanceof MultiConflictRule) {
            return $this->literals === $rule->getLiterals();
        }

        return false;
    }

    public function isAssertion(): bool
    {
        return false;
    }

    /**
     * @return never
     * @throws \RuntimeException
     */
    public function disable(): void
    {
        throw new \RuntimeException("Disabling multi conflict rules is not possible. Please contact composer at https://github.com/composer/composer to let us debug what lead to this situation.");
    }

    /**
     * Formats a rule as a string of the format (Literal1|Literal2|...)
     */
    public function __toString(): string
    {
        // TODO multi conflict?
        $result = $this->isDisabled() ? 'disabled(multi(' : '(multi(';

        foreach ($this->literals as $i => $literal) {
            if ($i !== 0) {
                $result .= '|';
            }
            $result .= $literal;
        }

        $result .= '))';

        return $result;
    }
}
