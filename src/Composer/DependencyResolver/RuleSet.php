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

use Composer\Repository\RepositorySet;

/**
 * @author Nils Adermann <naderman@naderman.de>
 * @implements \IteratorAggregate<Rule>
 * @internal
 * @final
 */
class RuleSet implements \IteratorAggregate, \Countable
{
    // highest priority => lowest number
    public const TYPE_PACKAGE = 0;
    public const TYPE_REQUEST = 1;
    public const TYPE_LEARNED = 4;

    /**
     * READ-ONLY: Lookup table for rule id to rule object
     *
     * @var array<int, Rule>
     */
    public $ruleById = [];

    const TYPES = [
        self::TYPE_PACKAGE => 'PACKAGE',
        self::TYPE_REQUEST => 'REQUEST',
        self::TYPE_LEARNED => 'LEARNED',
    ];

    /** @var array<self::TYPE_*, Rule[]> */
    protected $rules;

    /** @var 0|positive-int */
    protected $nextRuleId = 0;

    /** @var array<int|string, Rule|Rule[]> */
    protected $rulesByHash = [];

    public function __construct()
    {
        foreach ($this->getTypes() as $type) {
            $this->rules[$type] = [];
        }
    }

    /**
     * @param self::TYPE_* $type
     */
    public function add(Rule $rule, $type): void
    {
        if (!isset(self::TYPES[$type])) {
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
            $this->rules[$type] = [];
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
            $this->rulesByHash[$hash] = [$originalRule, $rule];
        }
    }

    public function count(): int
    {
        return $this->nextRuleId;
    }

    public function ruleById(int $id): Rule
    {
        return $this->ruleById[$id];
    }

    /** @return array<self::TYPE_*, Rule[]> */
    public function getRules(): array
    {
        return $this->rules;
    }

    public function getIterator(): RuleSetIterator
    {
        return new RuleSetIterator($this->getRules());
    }

    /**
     * @param  self::TYPE_*|array<self::TYPE_*> $types
     */
    public function getIteratorFor($types): RuleSetIterator
    {
        if (!\is_array($types)) {
            $types = [$types];
        }

        $allRules = $this->getRules();

        /** @var array<self::TYPE_*, Rule[]> $rules */
        $rules = [];

        foreach ($types as $type) {
            $rules[$type] = $allRules[$type];
        }

        return new RuleSetIterator($rules);
    }

    /**
     * @param array<self::TYPE_*>|self::TYPE_* $types
     */
    public function getIteratorWithout($types): RuleSetIterator
    {
        if (!\is_array($types)) {
            $types = [$types];
        }

        $rules = $this->getRules();

        foreach ($types as $type) {
            unset($rules[$type]);
        }

        return new RuleSetIterator($rules);
    }

    /**
     * @return array{self::TYPE_PACKAGE, self::TYPE_REQUEST, self::TYPE_LEARNED}
     */
    public function getTypes(): array
    {
        $types = self::TYPES;

        return array_keys($types);
    }

    public function getPrettyString(?RepositorySet $repositorySet = null, ?Request $request = null, ?Pool $pool = null, bool $isVerbose = false): string
    {
        $string = "\n";
        foreach ($this->rules as $type => $rules) {
            $string .= str_pad(self::TYPES[$type], 8, ' ') . ": ";
            foreach ($rules as $rule) {
                $string .= ($repositorySet && $request && $pool ? $rule->getPrettyString($repositorySet, $request, $pool, $isVerbose) : $rule)."\n";
            }
            $string .= "\n\n";
        }

        return $string;
    }

    public function __toString(): string
    {
        return $this->getPrettyString();
    }
}
