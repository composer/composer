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

    /**
     * READ-ONLY: The literals this rule consists of.
     * @var array
     */
    public $literals;

    protected $disabled;
    protected $type;
    protected $id;
    protected $reason;
    protected $reasonData;

    protected $job;

    protected $ruleHash;

    public function __construct(array $literals, $reason, $reasonData, $job = null)
    {
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

    public function getReason()
    {
        return $this->reason;
    }

    public function getReasonData()
    {
        return $this->reasonData;
    }

    public function getRequiredPackage()
    {
        if ($this->reason === self::RULE_JOB_INSTALL) {
            return $this->reasonData;
        }

        if ($this->reason === self::RULE_PACKAGE_REQUIRES) {
            return $this->reasonData->getTarget();
        }
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

    /**
     * @deprecated Use public literals member
     */
    public function getLiterals()
    {
        return $this->literals;
    }

    public function isAssertion()
    {
        return 1 === count($this->literals);
    }

    public function getPrettyString(Pool $pool, array $installedMap = array())
    {
        $ruleText = '';
        foreach ($this->literals as $i => $literal) {
            if ($i != 0) {
                $ruleText .= '|';
            }
            $ruleText .= $pool->literalToPrettyString($literal, $installedMap);
        }

        switch ($this->reason) {
            case self::RULE_INTERNAL_ALLOW_UPDATE:
                return $ruleText;

            case self::RULE_JOB_INSTALL:
                return "Install command rule ($ruleText)";

            case self::RULE_JOB_REMOVE:
                return "Remove command rule ($ruleText)";

            case self::RULE_PACKAGE_CONFLICT:
                $package1 = $pool->literalToPackage($this->literals[0]);
                $package2 = $pool->literalToPackage($this->literals[1]);

                return $package1->getPrettyString().' conflicts with '.$this->formatPackagesUnique($pool, array($package2)).'.';

            case self::RULE_PACKAGE_REQUIRES:
                $literals = $this->literals;
                $sourceLiteral = array_shift($literals);
                $sourcePackage = $pool->literalToPackage($sourceLiteral);

                $requires = array();
                foreach ($literals as $literal) {
                    $requires[] = $pool->literalToPackage($literal);
                }

                $text = $this->reasonData->getPrettyString($sourcePackage);
                if ($requires) {
                    $text .= ' -> satisfiable by ' . $this->formatPackagesUnique($pool, $requires) . '.';
                } else {
                    $targetName = $this->reasonData->getTarget();

                    // handle php extensions
                    if (0 === strpos($targetName, 'ext-')) {
                        $ext = substr($targetName, 4);
                        $error = extension_loaded($ext) ? 'has the wrong version ('.(phpversion($ext) ?: '0').') installed' : 'is missing from your system';

                        $text .= ' -> the requested PHP extension '.$ext.' '.$error.'.';
                    } elseif (0 === strpos($targetName, 'lib-')) {
                        // handle linked libs
                        $lib = substr($targetName, 4);

                        $text .= ' -> the requested linked library '.$lib.' has the wrong version installed or is missing from your system, make sure to have the extension providing it.';
                    } else {
                        $text .= ' -> no matching package found.';
                    }
                }

                return $text;

            case self::RULE_PACKAGE_OBSOLETES:
                return $ruleText;
            case self::RULE_INSTALLED_PACKAGE_OBSOLETES:
                return $ruleText;
            case self::RULE_PACKAGE_SAME_NAME:
                return 'Can only install one of: ' . $this->formatPackagesUnique($pool, $this->literals) . '.';
            case self::RULE_PACKAGE_IMPLICIT_OBSOLETES:
                return $ruleText;
            case self::RULE_LEARNED:
                return 'Conclusion: '.$ruleText;
            case self::RULE_PACKAGE_ALIAS:
                return $ruleText;
            default:
                return '('.$ruleText.')';
        }
    }

    protected function formatPackagesUnique($pool, array $packages)
    {
        $prepared = array();
        foreach ($packages as $package) {
            if (!is_object($package)) {
                $package = $pool->literalToPackage($package);
            }
            $prepared[$package->getName()]['name'] = $package->getPrettyName();
            $prepared[$package->getName()]['versions'][$package->getVersion()] = $package->getPrettyVersion();
        }
        foreach ($prepared as $name => $package) {
            $prepared[$name] = $package['name'].'['.implode(', ', $package['versions']).']';
        }

        return implode(', ', $prepared);
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
            $result .= $literal;
        }

        $result .= ')';

        return $result;
    }
}
