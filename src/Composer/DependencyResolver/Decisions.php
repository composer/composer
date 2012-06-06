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
    protected $decisionQueueFree = array();

    public function __construct($pool)
    {
        $this->pool = $pool;

        if (version_compare(PHP_VERSION, '5.3.4', '>=')) {
            $this->decisionMap = new \SplFixedArray($this->pool->getMaxId() + 1);
        } else {
            $this->decisionMap = array_fill(0, $this->pool->getMaxId() + 1, 0);
        }
    }

    protected function addDecision($literal, $level)
    {
        $packageId = abs($literal);

        $previousDecision = $this->decisionMap[$packageId];
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

    public function decide($literal, $level, $why, $addToFreeQueue = false)
    {
        $this->addDecision($literal, $level);
        $this->decisionQueue[] = array(
            self::DECISION_LITERAL => $literal,
            self::DECISION_REASON => $why,
        );

        if ($addToFreeQueue) {
            $this->decisionQueueFree[count($this->decisionQueue) - 1] = true;
        }
    }

    public function contain($literal)
    {
        $packageId = abs($literal);

        return (
            $this->decisionMap[$packageId] > 0 && $literal > 0 ||
            $this->decisionMap[$packageId] < 0 && $literal < 0
        );
    }

    public function satisfy($literal)
    {
        $packageId = abs($literal);

        return (
            $literal > 0 && $this->decisionMap[$packageId] > 0 ||
            $literal < 0 && $this->decisionMap[$packageId] < 0
        );
    }

    public function conflict($literal)
    {
        $packageId = abs($literal);

        return (
            ($this->decisionMap[$packageId] > 0 && $literal < 0) ||
            ($this->decisionMap[$packageId] < 0 && $literal > 0)
        );
    }

    public function decided($literalOrPackageId)
    {
        return $this->decisionMap[abs($literalOrPackageId)] != 0;
    }

    public function undecided($literalOrPackageId)
    {
        return $this->decisionMap[abs($literalOrPackageId)] == 0;
    }

    public function decidedInstall($literalOrPackageId)
    {
        return $this->decisionMap[abs($literalOrPackageId)] > 0;
    }

    public function decisionLevel($literalOrPackageId)
    {
        return abs($this->decisionMap[abs($literalOrPackageId)]);
    }

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

    public function atOffset($queueOffset)
    {
        return $this->decisionQueue[$queueOffset];
    }

    public function validOffset($queueOffset)
    {
        return $queueOffset >= 0 && $queueOffset < count($this->decisionQueue);
    }

    public function lastReason()
    {
        return $this->decisionQueue[count($this->decisionQueue) - 1][self::DECISION_REASON];
    }

    public function lastLiteral()
    {
        return $this->decisionQueue[count($this->decisionQueue) - 1][self::DECISION_LITERAL];
    }

    public function reset()
    {
        while ($decision = array_pop($this->decisionQueue)) {
            $this->decisionMap[abs($decision[self::DECISION_LITERAL])] = 0;
        }

        $this->decisionQueueFree = array();
    }

    public function resetToOffset($offset)
    {
        while (count($this->decisionQueue) > $offset + 1) {
            $decision = array_pop($this->decisionQueue);
            unset($this->decisionQueueFree[count($this->decisionQueue)]);
            $this->decisionMap[abs($decision[self::DECISION_LITERAL])] = 0;
        }
    }

    public function revertLast()
    {
        $this->decisionMap[abs($this->lastLiteral())] = 0;
        array_pop($this->decisionQueue);
    }

    public function count()
    {
        return count($this->decisionQueue);
    }

    public function rewind()
    {
        end($this->decisionQueue);
    }

    public function current()
    {
        return current($this->decisionQueue);
    }

    public function key()
    {
        return key($this->decisionQueue);
    }

    public function next()
    {
        return prev($this->decisionQueue);
    }

    public function valid()
    {
        return false !== current($this->decisionQueue);
    }

    public function isEmpty()
    {
        return count($this->decisionQueue) === 0;
    }
}
