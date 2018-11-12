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
 * The RuleWatchGraph efficiently propagates decisions to other rules
 *
 * All rules generated for solving a SAT problem should be inserted into the
 * graph. When a decision on a literal is made, the graph can be used to
 * propagate the decision to all other rules involving the literal, leading to
 * other trivial decisions resulting from unit clauses.
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class RuleWatchGraph
{
    protected $watchChains = array();

    /**
     * Inserts a rule node into the appropriate chains within the graph
     *
     * The node is prepended to the watch chains for each of the two literals it
     * watches.
     *
     * Assertions are skipped because they only depend on a single package and
     * have no alternative literal that could be true, so there is no need to
     * watch changes in any literals.
     *
     * @param RuleWatchNode $node The rule node to be inserted into the graph
     */
    public function insert(RuleWatchNode $node)
    {
        if ($node->getRule()->isAssertion()) {
            return;
        }

        foreach (array($node->watch1, $node->watch2) as $literal) {
            if (!isset($this->watchChains[$literal])) {
                $this->watchChains[$literal] = new RuleWatchChain;
            }

            $this->watchChains[$literal]->unshift($node);
        }
    }

    /**
     * Propagates a decision on a literal to all rules watching the literal
     *
     * If a decision, e.g. +A has been made, then all rules containing -A, e.g.
     * (-A|+B|+C) now need to satisfy at least one of the other literals, so
     * that the rule as a whole becomes true, since with +A applied the rule
     * is now (false|+B|+C) so essentially (+B|+C).
     *
     * This means that all rules watching the literal -A need to be updated to
     * watch 2 other literals which can still be satisfied instead. So literals
     * that conflict with previously made decisions are not an option.
     *
     * Alternatively it can occur that a unit clause results: e.g. if in the
     * above example the rule was (-A|+B), then A turning true means that
     * B must now be decided true as well.
     *
     * @param  int       $decidedLiteral The literal which was decided (A in our example)
     * @param  int       $level          The level at which the decision took place and at which
     *                                   all resulting decisions should be made.
     * @param  Decisions $decisions      Used to check previous decisions and to
     *                                   register decisions resulting from propagation
     * @return Rule|null If a conflict is found the conflicting rule is returned
     */
    public function propagateLiteral($decidedLiteral, $level, $decisions)
    {
        // we invert the decided literal here, example:
        // A was decided => (-A|B) now requires B to be true, so we look for
        // rules which are fulfilled by -A, rather than A.
        $literal = -$decidedLiteral;

        if (!isset($this->watchChains[$literal])) {
            return null;
        }

        $chain = $this->watchChains[$literal];

        $chain->rewind();
        while ($chain->valid()) {
            $node = $chain->current();
            $otherWatch = $node->getOtherWatch($literal);

            if (!$node->getRule()->isDisabled() && !$decisions->satisfy($otherWatch)) {
                $ruleLiterals = $node->getRule()->getLiterals();

                $alternativeLiterals = array_filter($ruleLiterals, function ($ruleLiteral) use ($literal, $otherWatch, $decisions) {
                    return $literal !== $ruleLiteral &&
                        $otherWatch !== $ruleLiteral &&
                        !$decisions->conflict($ruleLiteral);
                });

                if ($alternativeLiterals) {
                    reset($alternativeLiterals);
                    $this->moveWatch($literal, current($alternativeLiterals), $node);
                    continue;
                }

                if ($decisions->conflict($otherWatch)) {
                    return $node->getRule();
                }

                $decisions->decide($otherWatch, $level, $node->getRule());
            }

            $chain->next();
        }

        return null;
    }

    /**
     * Moves a rule node from one watch chain to another
     *
     * The rule node's watched literals are updated accordingly.
     *
     * @param int           $fromLiteral A literal the node used to watch
     * @param int           $toLiteral A literal the node should watch now
     * @param RuleWatchNode $node The rule node to be moved
     */
    protected function moveWatch($fromLiteral, $toLiteral, $node)
    {
        if (!isset($this->watchChains[$toLiteral])) {
            $this->watchChains[$toLiteral] = new RuleWatchChain;
        }

        $node->moveWatch($fromLiteral, $toLiteral);
        $this->watchChains[$fromLiteral]->remove();
        $this->watchChains[$toLiteral]->unshift($node);
    }
}
