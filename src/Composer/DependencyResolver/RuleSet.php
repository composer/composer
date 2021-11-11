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

use Composer\Repository\RepositorySet;

/**
 * @author Nils Adermann <naderman@naderman.de>
 * @implements \IteratorAggregate<Rule>
 */
class RuleSet implements \IteratorAggregate, \Countable
{
    // highest priority => lowest number
    const TYPE_PACKAGE = 0;
    const TYPE_REQUEST = 1;
    const TYPE_LEARNED = 4;

    /**
     * READ-ONLY: Lookup table for rule id to rule object
     *
     * @var array<int, Rule>
     */
    public $ruleById = array();

    /** @var array<0|1|4, string> */
    protected static $types = array(
        self::TYPE_PACKAGE => 'PACKAGE',
        self::TYPE_REQUEST => 'REQUEST',
        self::TYPE_LEARNED => 'LEARNED',
    );

    /** @var array<self::TYPE_*, Rule[]> */
    protected $rules;

    /** @var int */
    protected $nextRuleId = 0;

    /** @var array<string, Rule|Rule[]> */
    protected $rulesByHash = array();

    public function __construct()
    {
        foreach ($this->getTypes() as $type) {
            $this->rules[$type] = array();
        }
    }

    /**
     * @param self::TYPE_* $type
     * @return void
     */
    public function add(Rule $rule, $type)
    {
        if (!isset(self::$types[$type])) {
            throw new \OutOfBoundsException('Unknown rule type: ' . $type);
        }

        $hash = $rule->getHash();

        // Do not add if rule already exists
        if (isset($this->rulesByHash[$hash])) {
            $potentialDuplicates = $this->rulesByHash[$hash];
            if (\is_array($potentialDuplicates)) {
                foreach ($potentialDuplicates as $potentialDuplicate) {
                    if ($rule->equals($potentialDuplicate)) {
                        return;
                    }
                }
            } else {
                if ($rule->equals($potentialDuplicates)) {
                    return;
                }
            }
        }

        if (!isset($this->rules[$type])) {
            $this->rules[$type] = array();
        }

        $this->rules[$type][] = $rule;
        $this->ruleById[$this->nextRuleId] = $rule;
        $rule->setType($type);

        $this->nextRuleId++;

        if (!isset($this->rulesByHash[$hash])) {
            $this->rulesByHash[$hash] = $rule;
        } elseif (\is_array($this->rulesByHash[$hash])) {
            $this->rulesByHash[$hash][] = $rule;
        } else {
            $originalRule = $this->rulesByHash[$hash];
            $this->rulesByHash[$hash] = array($originalRule, $rule);
        }
    }

    /**
     * @return int
     */
    #[\ReturnTypeWillChange]
    public function count()
    {
        return $this->nextRuleId;
    }

    /**
     * @param int $id
     * @return Rule
     */
    public function ruleById($id)
    {
        return $this->ruleById[$id];
    }

    /** @return array<self::TYPE_*, Rule[]> */
    public function getRules()
    {
        return $this->rules;
    }

    /**
     * @return RuleSetIterator
     */
    #[\ReturnTypeWillChange]
    public function getIterator()
    {
        return new RuleSetIterator($this->getRules());
    }

    /**
     * @param  self::TYPE_*|array<self::TYPE_*> $types
     * @return RuleSetIterator
     */
    public function getIteratorFor($types)
    {
        if (!\is_array($types)) {
            $types = array($types);
        }

        $allRules = $this->getRules();

        /** @var array<self::TYPE_*, Rule[]> $rules */
        $rules = array();

        foreach ($types as $type) {
            $rules[$type] = $allRules[$type];
        }

        return new RuleSetIterator($rules);
    }

    /**
     * @param array<self::TYPE_*>|self::TYPE_* $types
     * @return RuleSetIterator
     */
    public function getIteratorWithout($types)
    {
        if (!\is_array($types)) {
            $types = array($types);
        }

        $rules = $this->getRules();

        foreach ($types as $type) {
            unset($rules[$type]);
        }

        return new RuleSetIterator($rules);
    }

    /** @return array{0: 0, 1: 1, 2: 4} */
    public function getTypes()
    {
        $types = self::$types;

        return array_keys($types);
    }

    /**
     * @param bool $isVerbose
     * @return string
     */
    public function getPrettyString(RepositorySet $repositorySet = null, Request $request = null, Pool $pool = null, $isVerbose = false)
    {
        $string = "\n";
        foreach ($this->rules as $type => $rules) {
            $string .= str_pad(self::$types[$type], 8, ' ') . ": ";
            foreach ($rules as $rule) {
                $string .= ($repositorySet && $request && $pool ? $rule->getPrettyString($repositorySet, $request, $pool, $isVerbose) : $rule)."\n";
            }
            $string .= "\n\n";
        }

        return $string;
    }

    public function __toString()
    {
        return $this->getPrettyString();
    }
}
