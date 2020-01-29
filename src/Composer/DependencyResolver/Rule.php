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

use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Repository\RepositorySet;

/**
 * @author Nils Adermann <naderman@naderman.de>
 * @author Ruben Gonzalez <rubenrua@gmail.com>
 */
abstract class Rule
{
    // reason constants
    const RULE_INTERNAL_ALLOW_UPDATE = 1;
    const RULE_ROOT_REQUIRE = 2;
    const RULE_FIXED = 3;
    const RULE_PACKAGE_CONFLICT = 6;
    const RULE_PACKAGE_REQUIRES = 7;
    const RULE_PACKAGE_OBSOLETES = 8;
    const RULE_INSTALLED_PACKAGE_OBSOLETES = 9;
    const RULE_PACKAGE_SAME_NAME = 10;
    const RULE_PACKAGE_IMPLICIT_OBSOLETES = 11;
    const RULE_LEARNED = 12;
    const RULE_PACKAGE_ALIAS = 13;

    // bitfield defs
    const BITFIELD_TYPE = 0;
    const BITFIELD_REASON = 8;
    const BITFIELD_DISABLED = 16;

    protected $bitfield;
    protected $request;
    protected $reasonData;

    /**
     * @param int                   $reason     A RULE_* constant describing the reason for generating this rule
     * @param Link|PackageInterface $reasonData
     */
    public function __construct($reason, $reasonData)
    {
        $this->reasonData = $reasonData;

        $this->bitfield = (0 << self::BITFIELD_DISABLED) |
            ($reason << self::BITFIELD_REASON) |
            (255 << self::BITFIELD_TYPE);
    }

    abstract public function getLiterals();

    abstract public function getHash();

    abstract public function equals(Rule $rule);

    public function getReason()
    {
        return ($this->bitfield & (255 << self::BITFIELD_REASON)) >> self::BITFIELD_REASON;
    }

    public function getReasonData()
    {
        return $this->reasonData;
    }

    public function getRequiredPackage()
    {
        $reason = $this->getReason();

        if ($reason === self::RULE_ROOT_REQUIRE) {
            return $this->reasonData['packageName'];
        }

        if ($reason === self::RULE_FIXED) {
            return $this->reasonData['package']->getName();
        }

        if ($reason === self::RULE_PACKAGE_REQUIRES) {
            return $this->reasonData->getTarget();
        }
    }

    public function setType($type)
    {
        $this->bitfield = ($this->bitfield & ~(255 << self::BITFIELD_TYPE)) | ((255 & $type) << self::BITFIELD_TYPE);
    }

    public function getType()
    {
        return ($this->bitfield & (255 << self::BITFIELD_TYPE)) >> self::BITFIELD_TYPE;
    }

    public function disable()
    {
        $this->bitfield = ($this->bitfield & ~(255 << self::BITFIELD_DISABLED)) | (1 << self::BITFIELD_DISABLED);
    }

    public function enable()
    {
        $this->bitfield &= ~(255 << self::BITFIELD_DISABLED);
    }

    public function isDisabled()
    {
        return (bool) (($this->bitfield & (255 << self::BITFIELD_DISABLED)) >> self::BITFIELD_DISABLED);
    }

    public function isEnabled()
    {
        return !(($this->bitfield & (255 << self::BITFIELD_DISABLED)) >> self::BITFIELD_DISABLED);
    }

    abstract public function isAssertion();

    public function getPrettyString(RepositorySet $repositorySet, Request $request, array $installedMap = array(), array $learnedPool = array())
    {
        $pool = $repositorySet->getPool();
        $literals = $this->getLiterals();

        $ruleText = '';
        foreach ($literals as $i => $literal) {
            if ($i != 0) {
                $ruleText .= '|';
            }
            $ruleText .= $pool->literalToPrettyString($literal, $installedMap);
        }

        switch ($this->getReason()) {
            case self::RULE_INTERNAL_ALLOW_UPDATE:
                return $ruleText;

            case self::RULE_ROOT_REQUIRE:
                $packageName = $this->reasonData['packageName'];
                $constraint = $this->reasonData['constraint'];

                $packages = $pool->whatProvides($packageName, $constraint);
                if (!$packages) {
                    return 'No package found to satisfy root composer.json require '.$packageName.($constraint ? ' '.$constraint->getPrettyString() : '');
                }

                return 'Root composer.json requires '.$packageName.($constraint ? ' '.$constraint->getPrettyString() : '').' -> satisfiable by '.$this->formatPackagesUnique($pool, $packages).'.';

            case self::RULE_FIXED:
                $package = $this->reasonData['package'];
                if ($this->reasonData['lockable']) {
                    return $package->getPrettyName().' is locked to version '.$package->getPrettyVersion().' and an update of this package was not requested.';
                }

                return $package->getPrettyName().' is present at version '.$package->getPrettyVersion() . ' and cannot be modified by Composer';

            case self::RULE_PACKAGE_CONFLICT:
                $package1 = $pool->literalToPackage($literals[0]);
                $package2 = $pool->literalToPackage($literals[1]);

                return $package1->getPrettyString().' conflicts with '.$this->formatPackagesUnique($pool, array($package2)).'.';

            case self::RULE_PACKAGE_REQUIRES:
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

                    $reason = Problem::getMissingPackageReason($repositorySet, $request, $targetName, $this->reasonData->getConstraint());

                    return $text . ' -> ' . $reason[1];
                }

                return $text;

            case self::RULE_PACKAGE_OBSOLETES:
                return $ruleText;
            case self::RULE_INSTALLED_PACKAGE_OBSOLETES:
                return $ruleText;
            case self::RULE_PACKAGE_SAME_NAME:
                return 'Same name, can only install one of: ' . $this->formatPackagesUnique($pool, $literals) . '.';
            case self::RULE_PACKAGE_IMPLICIT_OBSOLETES:
                return $ruleText;
            case self::RULE_LEARNED:
                // TODO not sure this is a good idea, most of these rules should be listed in the problem anyway
                $learnedString = '(learned rule, ';
                if (isset($learnedPool[$this->reasonData])) {
                    foreach ($learnedPool[$this->reasonData] as $learnedRule) {
                        $learnedString .= $learnedRule->getPrettyString($repositorySet, $request, $installedMap, $learnedPool);
                    }
                } else {
                    $learnedString .= 'reasoning unavailable';
                }
                $learnedString .= ')';

                return 'Conclusion: '.$ruleText.' '.$learnedString;
            case self::RULE_PACKAGE_ALIAS:
                return $ruleText;
            default:
                return '('.$ruleText.')';
        }
    }

    /**
     * @param Pool  $pool
     * @param array $packages
     *
     * @return string
     */
    protected function formatPackagesUnique($pool, array $packages)
    {
        $prepared = array();
        foreach ($packages as $index => $package) {
            if (!is_object($package)) {
                $packages[$index] = $pool->literalToPackage($package);
            }
        }

        return Problem::getPackageList($packages);
    }
}
