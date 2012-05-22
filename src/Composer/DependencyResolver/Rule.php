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
class Rule
{
    const RULE_INTERNAL_ALLOW_UPDATE = 1;
    const RULE_JOB_INSTALL = 2;
    const RULE_JOB_REMOVE = 3;
    const RULE_PACKAGE_CONFLICT = 6;
    const RULE_PACKAGE_REQUIRES = 7;
    const RULE_PACKAGE_OBSOLETES = 8;
    const RULE_INSTALLED_PACKAGE_OBSOLETES = 9;
    const RULE_PACKAGE_SAME_NAME = 10;
    const RULE_PACKAGE_IMPLICIT_OBSOLETES = 11;
    const RULE_LEARNED = 12;
    const RULE_PACKAGE_ALIAS = 13;

    protected $pool;

    protected $disabled;
    protected $literals;
    protected $type;
    protected $id;

    protected $job;

    protected $ruleHash;

    public function __construct(Pool $pool, array $literals, $reason, $reasonData, $job = null)
    {
        $this->pool = $pool;

        // sort all packages ascending by id
        sort($literals);

        $this->literals = $literals;
        $this->reason = $reason;
        $this->reasonData = $reasonData;

        $this->disabled = false;

        $this->job = $job;

        $this->type = -1;

        $this->ruleHash = substr(md5(implode(',', $this->literals)), 0, 5);
    }

    public function getHash()
    {
        return $this->ruleHash;
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getJob()
    {
        return $this->job;
    }

    /**
     * Checks if this rule is equal to another one
     *
     * Ignores whether either of the rules is disabled.
     *
     * @param  Rule $rule The rule to check against
     * @return bool Whether the rules are equal
     */
    public function equals(Rule $rule)
    {
        if ($this->ruleHash !== $rule->ruleHash) {
            return false;
        }

        if (count($this->literals) != count($rule->literals)) {
            return false;
        }

        for ($i = 0, $n = count($this->literals); $i < $n; $i++) {
            if ($this->literals[$i] !== $rule->literals[$i]) {
                return false;
            }
        }

        return true;
    }

    public function setType($type)
    {
        $this->type = $type;
    }

    public function getType()
    {
        return $this->type;
    }

    public function disable()
    {
        $this->disabled = true;
    }

    public function enable()
    {
        $this->disabled = false;
    }

    public function isDisabled()
    {
        return $this->disabled;
    }

    public function isEnabled()
    {
        return !$this->disabled;
    }

    public function getLiterals()
    {
        return $this->literals;
    }

    public function isAssertion()
    {
        return 1 === count($this->literals);
    }

    public function toHumanReadableString()
    {
        $ruleText = '';
        foreach ($this->literals as $i => $literal) {
            if ($i != 0) {
                $ruleText .= '|';
            }
            $ruleText .= $this->pool->literalToString($literal);
        }

        switch ($this->reason) {
            case self::RULE_INTERNAL_ALLOW_UPDATE:
                return $ruleText;

            case self::RULE_JOB_INSTALL:
                return "Install command rule ($ruleText)";

            case self::RULE_JOB_REMOVE:
                return "Remove command rule ($ruleText)";

            case self::RULE_PACKAGE_CONFLICT:
                $package1 = $this->pool->literalToPackage($this->literals[0]);
                $package2 = $this->pool->literalToPackage($this->literals[1]);

                return 'Package "'.$package1.'" conflicts with "'.$package2.'"';

            case self::RULE_PACKAGE_REQUIRES:
                $literals = $this->literals;
                $sourceLiteral = array_shift($literals);
                $sourcePackage = $this->pool->literalToPackage($sourceLiteral);

                $requires = array();
                foreach ($literals as $literal) {
                    $requires[] = $this->pool->literalToPackage($literal);
                }

                $text = 'Package "'.$sourcePackage.'" contains the rule '.$this->reasonData.'. ';
                if ($requires) {
                    $text .= 'Any of these packages satisfy the dependency: '.implode(', ', $requires).'.';
                } else {
                    $text .= 'No package satisfies this dependency.';
                }

                return $text;

            case self::RULE_PACKAGE_OBSOLETES:
                return $ruleText;
            case self::RULE_INSTALLED_PACKAGE_OBSOLETES:
                return $ruleText;
            case self::RULE_PACKAGE_SAME_NAME:
                return $ruleText;
            case self::RULE_PACKAGE_IMPLICIT_OBSOLETES:
                return $ruleText;
            case self::RULE_LEARNED:
                return 'learned: '.$ruleText;
            case self::RULE_PACKAGE_ALIAS:
                return $ruleText;
        }
    }

    /**
     * Formats a rule as a string of the format (Literal1|Literal2|...)
     *
     * @return string
     */
    public function __toString()
    {
        $result = ($this->isDisabled()) ? 'disabled(' : '(';

        foreach ($this->literals as $i => $literal) {
            if ($i != 0) {
                $result .= '|';
            }
            $result .= $this->pool->literalToString($literal);
        }

        $result .= ')';

        return $result;
    }
}
