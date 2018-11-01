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
 * Stores decisions on installing, removing or keeping packages
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class Decisions implements \Iterator, \Countable
{
    const DECISION_LITERAL = 0;
    const DECISION_REASON = 1;

    protected $pool;
    protected $decisionMap;
    protected $decisionQueue = array();

    /**
     * Create the decisions instance
     * 
     * @param \Composer\DependencyResolver\Pool $pool
     */
    public function __construct($pool)
    {
        $this->pool = $pool;
        $this->decisionMap = array();
    }

    /**
     * {@inheritDoc}
     */
    public function decide($literal, $level, $why)
    {
        $this->addDecision($literal, $level);
        $this->decisionQueue[] = array(
            self::DECISION_LITERAL => $literal,
            self::DECISION_REASON => $why,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function satisfy($literal)
    {
        $packageId = abs($literal);

        return (
            $literal > 0 && isset($this->decisionMap[$packageId]) && $this->decisionMap[$packageId] > 0 ||
            $literal < 0 && isset($this->decisionMap[$packageId]) && $this->decisionMap[$packageId] < 0
        );
    }

    /**
     * {@inheritDoc}
     */
    public function conflict($literal)
    {
        $packageId = abs($literal);

        return (
            (isset($this->decisionMap[$packageId]) && $this->decisionMap[$packageId] > 0 && $literal < 0) ||
            (isset($this->decisionMap[$packageId]) && $this->decisionMap[$packageId] < 0 && $literal > 0)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function decided($literalOrPackageId)
    {
        return !empty($this->decisionMap[abs($literalOrPackageId)]);
    }

    /**
     * {@inheritDoc}
     */
    public function undecided($literalOrPackageId)
    {
        return empty($this->decisionMap[abs($literalOrPackageId)]);
    }

    /**
     * {@inheritDoc}
     */
    public function decidedInstall($literalOrPackageId)
    {
        $packageId = abs($literalOrPackageId);

        return isset($this->decisionMap[$packageId]) && $this->decisionMap[$packageId] > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function decisionLevel($literalOrPackageId)
    {
        $packageId = abs($literalOrPackageId);
        if (isset($this->decisionMap[$packageId])) {
            return abs($this->decisionMap[$packageId]);
        }

        return 0;
    }

    /**
     * {@inheritDoc}
     */
    public function decisionRule($literalOrPackageId)
    {
        $packageId = abs($literalOrPackageId);

        foreach ($this->decisionQueue as $i => $decision) {
            if ($packageId === abs($decision[self::DECISION_LITERAL])) {
                return $decision[self::DECISION_REASON];
            }
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function atOffset($queueOffset)
    {
        return $this->decisionQueue[$queueOffset];
    }

    /**
     * {@inheritDoc}
     */
    public function validOffset($queueOffset)
    {
        return $queueOffset >= 0 && $queueOffset < count($this->decisionQueue);
    }

    /**
     * {@inheritDoc}
     */
    public function lastReason()
    {
        return $this->decisionQueue[count($this->decisionQueue) - 1][self::DECISION_REASON];
    }

    /**
     * {@inheritDoc}
     */
    public function lastLiteral()
    {
        return $this->decisionQueue[count($this->decisionQueue) - 1][self::DECISION_LITERAL];
    }

    /**
     * {@inheritDoc}
     */
    public function reset()
    {
        while ($decision = array_pop($this->decisionQueue)) {
            $this->decisionMap[abs($decision[self::DECISION_LITERAL])] = 0;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function resetToOffset($offset)
    {
        while (count($this->decisionQueue) > $offset + 1) {
            $decision = array_pop($this->decisionQueue);
            $this->decisionMap[abs($decision[self::DECISION_LITERAL])] = 0;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function revertLast()
    {
        $this->decisionMap[abs($this->lastLiteral())] = 0;
        array_pop($this->decisionQueue);
    }

    /**
     * {@inheritDoc}
     */
    public function count()
    {
        return count($this->decisionQueue);
    }

    /**
     * {@inheritDoc}
     */
    public function rewind()
    {
        end($this->decisionQueue);
    }

    /**
     * {@inheritDoc}
     */
    public function current()
    {
        return current($this->decisionQueue);
    }

    /**
     * {@inheritDoc}
     */
    public function key()
    {
        return key($this->decisionQueue);
    }

    /**
     * {@inheritDoc}
     */
    public function next()
    {
        return prev($this->decisionQueue);
    }

    /**
     * {@inheritDoc}
     */
    public function valid()
    {
        return false !== current($this->decisionQueue);
    }

    /**
     * {@inheritDoc}
     */
    public function isEmpty()
    {
        return count($this->decisionQueue) === 0;
    }

    /**
     * Add decision.
     * 
     * @param  int    $literal
     * @param  string $level
     * @throws \Composer\DependencyResolver\SolverBugException
     */
    protected function addDecision($literal, $level)
    {
        $packageId = abs($literal);

        $previousDecision = isset($this->decisionMap[$packageId]) ? $this->decisionMap[$packageId] : null;
        if ($previousDecision != 0) {
            $literalString = $this->pool->literalToString($literal);
            $package = $this->pool->literalToPackage($literal);
            throw new SolverBugException(
                "Trying to decide $literalString on level $level, even though $package was previously decided as ".(int) $previousDecision."."
            );
        }

        if ($literal > 0) {
            $this->decisionMap[$packageId] = $level;
        } else {
            $this->decisionMap[$packageId] = -$level;
        }
    }
}
