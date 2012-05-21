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
 * @author Nils Adermann <naderman@naderman.de>
 */
class RuleWatchGraph
{
    protected $watchChains = array();

    /**
     * Alters watch chains for a rule.
     *
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

    public function contains($literal)
    {
        return isset($this->watchChains[$literal]);
    }

    public function propagateLiteral($literal, $level, $skipCallback, $conflictCallback, $decideCallback)
    {
        if (!isset($this->watchChains[$literal])) {
            return null;
        }

        $chain = $this->watchChains[$literal];

        $chain->rewind();
        while ($chain->valid()) {
            $node = $chain->current();
            $otherWatch = $node->getOtherWatch($literal);

            if (!$node->getRule()->isDisabled() && !call_user_func($skipCallback, $otherWatch)) {
                $ruleLiterals = $node->getRule()->getLiterals();

                $alternativeLiterals = array_filter($ruleLiterals, function ($ruleLiteral) use ($literal, $otherWatch, $conflictCallback) {
                    return $literal !== $ruleLiteral &&
                        $otherWatch !== $ruleLiteral &&
                        !call_user_func($conflictCallback, $ruleLiteral);
                });

                if ($alternativeLiterals) {
                    reset($alternativeLiterals);
                    $this->moveWatch($literal, current($alternativeLiterals), $node);
                    continue;
                }

                if (call_user_func($conflictCallback, $otherWatch)) {
                    return $node->getRule();
                }

                call_user_func($decideCallback, $otherWatch, $level, $node->getRule());
            }

            $chain->next();
        }

        return null;
    }

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
