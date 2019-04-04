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

/**
 * @author Nils Adermann <naderman@naderman.de>
 * @author Ruben Gonzalez <rubenrua@gmail.com>
 */
abstract class Rule
{
    // reason constants
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

    // bitfield defs
    const BITFIELD_TYPE = 0;
    const BITFIELD_REASON = 8;
    const BITFIELD_DISABLED = 16;

    protected $bitfield;
    protected $job;
    protected $reasonData;

    /**
     * @param int                   $reason     A RULE_* constant describing the reason for generating this rule
     * @param Link|PackageInterface $reasonData
     * @param array                 $job        The job this rule was created from
     */
    public function __construct($reason, $reasonData, $job = null)
    {
        $this->reasonData = $reasonData;

        if ($job) {
            $this->job = $job;
        }

        $this->bitfield = (0 << self::BITFIELD_DISABLED) |
            ($reason << self::BITFIELD_REASON) |
            (255 << self::BITFIELD_TYPE);
    }

    abstract public function getLiterals();

    abstract public function getHash();

    public function getJob()
    {
        return $this->job;
    }

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
        if ($this->getReason() === self::RULE_JOB_INSTALL) {
            return $this->reasonData;
        }

        if ($this->getReason() === self::RULE_PACKAGE_REQUIRES) {
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

    public function getPrettyString(Pool $pool, array $installedMap = array())
    {
        $literals = $this->getLiterals();

        $ruleText = '';
        foreach ($literals as $i => $literal) {
            if ($i != 0) {
                $ruleText .= '|';
            }
            $ruleText .= $pool->literalToPrettyString($literal, $installedMap);
        }

        $prettyRuleText = "";

        switch ($this->getReason()) {
            case self::RULE_INTERNAL_ALLOW_UPDATE:
                $prettyRuleText = $ruleText;
                break;

            case self::RULE_JOB_INSTALL:
                $prettyRuleText = "Install command rule ($ruleText)";
                break;

            case self::RULE_JOB_REMOVE:
                $prettyRuleText = "Remove command rule ($ruleText)";
                break;

            case self::RULE_PACKAGE_CONFLICT:
                $package1 = $pool->literalToPackage($literals[0]);
                $package2 = $pool->literalToPackage($literals[1]);

                $prettyRuleText = $package1->getPrettyString().' conflicts with '.$this->formatPackagesUnique($pool, array($package2)).'.';
                break;

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

                    if ($targetName === 'php' || $targetName === 'php-64bit' || $targetName === 'hhvm') {
                        // handle php/hhvm
                        if (defined('HHVM_VERSION')) {
                            $prettyRuleText = $text . ' -> your HHVM version does not satisfy that requirement.';
                            break;
                        }

                        $packages = $pool->whatProvides($targetName);
                        $package = count($packages) ? current($packages) : phpversion();

                        if ($targetName === 'hhvm') {
                            if ($package instanceof CompletePackage) {
                                $prettyRuleText = $text . ' -> your HHVM version ('.$package->getPrettyVersion().') does not satisfy that requirement.';
                                break;
                            } else {
                                $prettyRuleText = $text . ' -> you are running this with PHP and not HHVM.';
                                break;
                            }
                        }


                        if (!($package instanceof CompletePackage)) {
                            $prettyRuleText = $text . ' -> your PHP version ('.phpversion().') does not satisfy that requirement.';
                            break;
                        }

                        $extra = $package->getExtra();

                        if (!empty($extra['config.platform'])) {
                            $text .= ' -> your PHP version ('.phpversion().') overridden by "config.platform.php" version ('.$package->getPrettyVersion().') does not satisfy that requirement.';
                        } else {
                            $text .= ' -> your PHP version ('.$package->getPrettyVersion().') does not satisfy that requirement.';
                        }

                        $prettyRuleText = $text;
                        break;
                    }

                    if (0 === strpos($targetName, 'ext-')) {
                        // handle php extensions
                        $ext = substr($targetName, 4);
                        $error = extension_loaded($ext) ? 'has the wrong version ('.(phpversion($ext) ?: '0').') installed' : 'is missing from your system';

                        $prettyRuleText = $text . ' -> the requested PHP extension '.$ext.' '.$error.'.';
                        break;
                    }

                    if (0 === strpos($targetName, 'lib-')) {
                        // handle linked libs
                        $lib = substr($targetName, 4);

                        $prettyRuleText = $text . ' -> the requested linked library '.$lib.' has the wrong version installed or is missing from your system, make sure to have the extension providing it.';
                        break;
                    }

                    if ($providers = $pool->whatProvides($targetName, $this->reasonData->getConstraint(), true, true)) {
                        $prettyRuleText = $text . ' -> satisfiable by ' . $this->formatPackagesUnique($pool, $providers) .' but these conflict with your requirements or minimum-stability.';
                        break;
                    }

                    $prettyRuleText = $text . ' -> no matching package found.';
                    break;
                }

                $prettyRuleText = $text;
                break;

            case self::RULE_PACKAGE_OBSOLETES:
                $prettyRuleText = $ruleText;
                break;

            case self::RULE_INSTALLED_PACKAGE_OBSOLETES:
                $prettyRuleText = $ruleText;
                break;

            case self::RULE_PACKAGE_SAME_NAME:
                $prettyRuleText = 'Can only install one of: ' . $this->formatPackagesUnique($pool, $literals) . '.';
                break;

            case self::RULE_PACKAGE_IMPLICIT_OBSOLETES:
                $prettyRuleText = $ruleText;
                break;

            case self::RULE_LEARNED:
                $prettyRuleText =  'Conclusion: '.$ruleText;
                break;

            case self::RULE_PACKAGE_ALIAS:
                $prettyRuleText = $ruleText;
                break;

            default:
                $prettyRuleText = '('.$ruleText.')';
                break;
        }

        return $this->simplifyRuleText($prettyRuleText);
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
     * Simplifies some rule texts so that they're more human readable and actionable.
     * @param   string    $prettyRuleText
     * 
     * @return  string
     */
    private function simplifyRuleText($prettyRuleText) {
        // Simplifying multiple "don't install" messages
        $texts = explode('|', $prettyRuleText);
        $occurances = array();
        $count = 0;
        foreach($texts as $text) {
            preg_match_all('/(don\'t\sinstall)\s(.+.)/', $text, $matches);
            if($matches[0]) {
                $count++;
                array_push($occurances,$matches);
            }
        }
        if($count >=2) {
            $prefix = "Install at most one of these : ";
            $separator = ", ";
            $packageNames = "";
            foreach($occurances as $i => $match) {
                $packageNames.=$match[2][0];
                if($i !== $count-1) {
                    $packageNames.=$separator;
                }
            }
            $prettyText = $prefix.$packageNames;
            return $prettyText;
        }
        return $prettyRuleText;
    }
}
