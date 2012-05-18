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
     * Next1/2 always points to the next rule that is watching the same package.
     * The watches array contains rules to start from for each package
     *
     */
    public function insert(RuleWatchNode $node)
    {
        // skip simple assertions of the form (A) or (-A)
        if ($node->getRule()->isAssertion()) {
            return;
        }

        if (!isset($this->watches[$node->watch1])) {
            $this->watches[$node->watch1] = null;
        }

        $node->next1 = $this->watches[$node->watch1];
        $this->watches[$node->watch1] = $node;

        if (!isset($this->watches[$node->watch2])) {
            $this->watches[$node->watch2] = null;
        }

        $node->next2 = $this->watches[$node->watch2];
        $this->watches[$node->watch2] = $node;
    }

    public function contains($literalId)
    {
        return isset($this->watches[$literalId]);
    }

    public function walkLiteral($literalId, $level, $skipCallback, $conflictCallback, $decideCallback)
    {
        if (!isset($this->watches[$literalId])) {
            return;
        }

        $prevNode = null;
        for ($node = $this->watches[$literalId]; $node !== null; $prevNode = $node, $node = $nextNode) {
            $nextNode = $node->getNext($literalId);

            if ($node->getRule()->isDisabled()) {
                continue;
            }

            $otherWatch = $node->getOtherWatch($literalId);

            if (call_user_func($skipCallback, $otherWatch)) {
                continue;
            }

            $ruleLiterals = $node->getRule()->getLiterals();

            if (sizeof($ruleLiterals) > 2) {
                foreach ($ruleLiterals as $ruleLiteral) {
                    if ($otherWatch !== $ruleLiteral->getId() &&
                        !call_user_func($conflictCallback, $ruleLiteral->getId())) {

                        $node = $this->moveWatch($literalId, $ruleLiteral->getId(), $prevNode, $node, $nextNode);

                        continue 2;
                    }
                }
            }

            // yay, we found a unit clause! try setting it to true
            if (call_user_func($conflictCallback, $otherWatch)) {
                return $node->getRule();
            }

            call_user_func($decideCallback, $otherWatch, $level, $node->getRule());
        }

        return null;
    }

    public function moveWatch($fromLiteral, $toLiteral, $prevNode, $node, $nextNode) {
        if ($fromLiteral == $node->watch1) {
            $node->watch1 = $toLiteral;
            $node->next1 = (isset($this->watches[$toLiteral])) ? $this->watches[$toLiteral] : null;
        } else {
            $node->watch2 = $toLiteral;
            $node->next2 = (isset($this->watches[$toLiteral])) ? $this->watches[$toLiteral] : null;
        }

        if ($prevNode) {
            if ($prevNode->next1 === $node) {
                $prevNode->next1 = $nextNode;
            } else {
                $prevNode->next2 = $nextNode;
            }
        } else {
            $this->watches[$fromLiteral] = $nextNode;
        }

        $this->watches[$toLiteral] = $node;

        if ($prevNode) {
            return $prevNode;
        }

        $tmpNode = new RuleWatchNode(new Rule(array(), null, null));
        $tmpNode->watch1 = $fromLiteral;
        $tmpNode->next1 = $nextNode;
        $tmpNode->watch2 = $fromLiteral;
        $tmpNode->next2 = $nextNode;

        return $tmpNode;
    }
}
