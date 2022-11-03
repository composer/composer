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
 * Stores decisions on installing, removing or keeping packages
 *
 * @author Nils Adermann <naderman@naderman.de>
 * @implements \Iterator<array{0: int, 1: Rule}>
 */
class Decisions implements \Iterator, \Countable
{
    public const DECISION_LITERAL = 0;
    public const DECISION_REASON = 1;

    /** @var Pool */
    protected $pool;
    /** @var array<int, int> */
    protected $decisionMap;
    /**
     * @var array<array{0: int, 1: Rule}>
     */
    protected $decisionQueue = [];

    public function __construct(Pool $pool)
    {
        $this->pool = $pool;
        $this->decisionMap = [];
    }

    public function decide(int $literal, int $level, Rule $why): void
    {
        $this->addDecision($literal, $level);
        $this->decisionQueue[] = [
            self::DECISION_LITERAL => $literal,
            self::DECISION_REASON => $why,
        ];
    }

    public function satisfy(int $literal): bool
    {
        $packageId = abs($literal);

        return (
            $literal > 0 && isset($this->decisionMap[$packageId]) && $this->decisionMap[$packageId] > 0 ||
            $literal < 0 && isset($this->decisionMap[$packageId]) && $this->decisionMap[$packageId] < 0
        );
    }

    public function conflict(int $literal): bool
    {
        $packageId = abs($literal);

        return (
            (isset($this->decisionMap[$packageId]) && $this->decisionMap[$packageId] > 0 && $literal < 0) ||
            (isset($this->decisionMap[$packageId]) && $this->decisionMap[$packageId] < 0 && $literal > 0)
        );
    }

    public function decided(int $literalOrPackageId): bool
    {
        return !empty($this->decisionMap[abs($literalOrPackageId)]);
    }

    public function undecided(int $literalOrPackageId): bool
    {
        return empty($this->decisionMap[abs($literalOrPackageId)]);
    }

    public function decidedInstall(int $literalOrPackageId): bool
    {
        $packageId = abs($literalOrPackageId);

        return isset($this->decisionMap[$packageId]) && $this->decisionMap[$packageId] > 0;
    }

    public function decisionLevel(int $literalOrPackageId): int
    {
        $packageId = abs($literalOrPackageId);
        if (isset($this->decisionMap[$packageId])) {
            return abs($this->decisionMap[$packageId]);
        }

        return 0;
    }

    public function decisionRule(int $literalOrPackageId): ?Rule
    {
        $packageId = abs($literalOrPackageId);

        foreach ($this->decisionQueue as $decision) {
            if ($packageId === abs($decision[self::DECISION_LITERAL])) {
                return $decision[self::DECISION_REASON];
            }
        }

        return null;
    }

    /**
     * @return array{0: int, 1: Rule} a literal and decision reason
     */
    public function atOffset(int $queueOffset): array
    {
        return $this->decisionQueue[$queueOffset];
    }

    public function validOffset(int $queueOffset): bool
    {
        return $queueOffset >= 0 && $queueOffset < \count($this->decisionQueue);
    }

    public function lastReason(): Rule
    {
        return $this->decisionQueue[\count($this->decisionQueue) - 1][self::DECISION_REASON];
    }

    public function lastLiteral(): int
    {
        return $this->decisionQueue[\count($this->decisionQueue) - 1][self::DECISION_LITERAL];
    }

    public function reset(): void
    {
        while ($decision = array_pop($this->decisionQueue)) {
            $this->decisionMap[abs($decision[self::DECISION_LITERAL])] = 0;
        }
    }

    /**
     * @param int<-1, max> $offset
     */
    public function resetToOffset(int $offset): void
    {
        while (\count($this->decisionQueue) > $offset + 1) {
            $decision = array_pop($this->decisionQueue);
            $this->decisionMap[abs($decision[self::DECISION_LITERAL])] = 0;
        }
    }

    public function revertLast(): void
    {
        $this->decisionMap[abs($this->lastLiteral())] = 0;
        array_pop($this->decisionQueue);
    }

    public function count(): int
    {
        return \count($this->decisionQueue);
    }

    public function rewind(): void
    {
        end($this->decisionQueue);
    }

    /**
     * @return array{0: int, 1: Rule}|false
     */
    #[\ReturnTypeWillChange]
    public function current()
    {
        return current($this->decisionQueue);
    }

    public function key(): ?int
    {
        return key($this->decisionQueue);
    }

    public function next(): void
    {
        prev($this->decisionQueue);
    }

    public function valid(): bool
    {
        return false !== current($this->decisionQueue);
    }

    public function isEmpty(): bool
    {
        return \count($this->decisionQueue) === 0;
    }

    protected function addDecision(int $literal, int $level): void
    {
        $packageId = abs($literal);

        $previousDecision = $this->decisionMap[$packageId] ?? 0;
        if ($previousDecision !== 0) {
            $literalString = $this->pool->literalToPrettyString($literal, []);
            $package = $this->pool->literalToPackage($literal);
            throw new SolverBugException(
                "Trying to decide $literalString on level $level, even though $package was previously decided as ".$previousDecision."."
            );
        }

        if ($literal > 0) {
            $this->decisionMap[$packageId] = $level;
        } else {
            $this->decisionMap[$packageId] = -$level;
        }
    }

    public function toString(?Pool $pool = null): string
    {
        $decisionMap = $this->decisionMap;
        ksort($decisionMap);
        $str = '[';
        foreach ($decisionMap as $packageId => $level) {
            $str .= (($pool) ? $pool->literalToPackage($packageId) : $packageId).':'.$level.',';
        }
        $str .= ']';

        return $str;
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
