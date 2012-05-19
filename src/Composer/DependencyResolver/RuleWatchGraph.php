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
    protected $watches = array();

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
            if (!isset($this->watches[$literal])) {
                $this->watches[$literal] = new RuleWatchChain;
            }

            $this->watches[$literal]->unshift($node);
        }
    }

    public function contains($literal)
    {
        return isset($this->watches[$literal]);
    }

    public function walkLiteral($literal, $level, $skipCallback, $conflictCallback, $decideCallback)
    {
        if (!isset($this->watches[$literal])) {
            return null;
        }

        $this->watches[$literal]->rewind();
        while ($this->watches[$literal]->valid()) {
            $node = $this->watches[$literal]->current();
            $otherWatch = $node->getOtherWatch($literal);

            if (!$node->getRule()->isDisabled() && !call_user_func($skipCallback, $otherWatch)) {
                $ruleLiterals = $node->getRule()->getLiterals();

                foreach ($ruleLiterals as $ruleLiteral) {
                    if ($literal !== $ruleLiteral &&
                        $otherWatch !== $ruleLiteral &&
                        !call_user_func($conflictCallback, $ruleLiteral)) {

                        $this->moveWatch($literal, $ruleLiteral, $node);

                        continue 2;
                    }
                }

                if (call_user_func($conflictCallback, $otherWatch)) {
                    return $node->getRule();
                }

                call_user_func($decideCallback, $otherWatch, $level, $node->getRule());
            }

            $this->watches[$literal]->next();
        }

        return null;
    }

    protected function moveWatch($fromLiteral, $toLiteral, $node)
    {
        if (!isset($this->watches[$toLiteral])) {
            $this->watches[$toLiteral] = new RuleWatchChain;
        }

        $node->moveWatch($fromLiteral, $toLiteral);
        $this->watches[$fromLiteral]->remove();
        $this->watches[$toLiteral]->unshift($node);
    }
}
