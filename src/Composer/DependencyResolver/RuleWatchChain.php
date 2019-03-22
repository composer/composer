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

use Composer\Package\AliasPackage;

/**
 * Provides an iterator over rule watches for a given literal.
 *
 * The rules expressing a conflict between two different versions of packages
 * that provide the same name are generated only as needed during iteration
 * rather than generating them all upfront, because their numbers grow
 * combinatorially with each release of a package and would dominate Composer's
 * overall memory utilization.
 *
 * @author Nils Adermann <naderman@naderman.de>
 * @author Mike Baynton <mike@mbaynton.com>
 */
class RuleWatchChain implements \Iterator
{
    protected $offset = 0;
    protected $literal;
    protected $pool;
    /** @var \Composer\Package\PackageInterface $ourPackage */
    protected $ourPackage;
    protected $currentComputedRuleWatchNode;

    /**
     * @var RuleWatchNode[] $interestingRuleWatches
     * Watches explicitly added; associated to inter-package rules.
     */
    protected $interestingRuleWatches;

    /**
     * @var \SplQueue|null $otherProvidesLiterals
     */
    protected $otherProvidesLiterals;

    public function __construct($literal, Pool $pool) {
        $this->literal = $literal;
        $this->pool = $pool;
        $this->offset = 0;
        $this->interestingRuleWatches = array();
        $this->otherProvidesLiterals = null;
        $this->currentComputedRuleWatchNode = null;
        $this->ourPackage = $this->pool->literalToPackage($this->literal);
    }

    public function unshift(RuleWatchNode $watch) {
        array_unshift($this->interestingRuleWatches, $watch);
    }

    public function next()
    {
        $this->offset++;
        if ($this->offset >= count($this->interestingRuleWatches)) {
            $this->queueUpComputedWatchNode();
        }
    }

    protected function queueUpComputedWatchNode() {
        $this->ensureSpmeLiterals();
        if ($this->otherProvidesLiterals->count()) {
            list($otherProvidesLiteral, $reason) = $this->otherProvidesLiterals->dequeue();
            $this->currentComputedRuleWatchNode = new RuleWatchNode(
                new Rule2Literals($this->literal, $otherProvidesLiteral, $reason, $this->ourPackage)
            );
        } else {
            $this->currentComputedRuleWatchNode = null;
            $this->otherProvidesLiterals = false;
        }
    }

    public function current()
    {
        if ($this->offset < count($this->interestingRuleWatches)) {
            return $this->interestingRuleWatches[$this->offset];
        } else {
            return $this->currentComputedRuleWatchNode;
        }
    }

    public function valid()
    {
        if ($this->offset < count($this->interestingRuleWatches)) {
            return true;
        }

        if ($this->otherProvidesLiterals === false) {
            return false;
        }

        return $this->currentComputedRuleWatchNode !== null;
    }

    public function rewind()
    {
        $this->offset = -1;
        $this->otherProvidesLiterals = null;

        $this->next();
    }

    public function key()
    {
        return $this->offset;
    }

    protected function ensureSpmeLiterals() {
        if ($this->otherProvidesLiterals !== null) {
            return;
        }

        $this->otherProvidesLiterals = new \SplQueue();

        if ($this->literal < 0) {
            $ourPackageName = $this->ourPackage->getName();
            $otherProviders = $this->pool->whatProvides($ourPackageName, null);

            foreach ($otherProviders as $provider) {
                if ($provider === $this->ourPackage) {
                    continue;
                }

                if (! (($this->ourPackage instanceof AliasPackage) && $this->ourPackage->getAliasOf() === $provider)
                    && !RuleSetGenerator::obsoleteImpossibleForAlias($this->ourPackage, $provider)) {
                    $reason = ($ourPackageName == $provider->getName()) ? Rule::RULE_PACKAGE_SAME_NAME : Rule::RULE_PACKAGE_IMPLICIT_OBSOLETES;
                    $this->otherProvidesLiterals->enqueue(array(-$provider->getId(), $reason));
                }
            }
        }
    }

    /**
     * Moves the internal iterator to the specified offset
     *
     * @param int $offset The offset to seek to.
     */
    public function seek($offset)
    {
        $this->rewind();
        for ($i = 0; $i < $offset; $i++, $this->next());
    }

    /**
     * Removes the current element from the list
     */
    public function remove()
    {
        $offset = $this->key();
        if ($offset >= count($this->interestingRuleWatches)) {
            throw new \LogicException('Watch nodes in the computed range cannot be removed.');
        }
        array_splice($this->interestingRuleWatches, $offset, 1);

        if ($offset == count($this->interestingRuleWatches)) {
            $this->queueUpComputedWatchNode();
        }
    }

}
