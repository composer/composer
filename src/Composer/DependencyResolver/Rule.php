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
        return isset($this->job) ? $this->job : null;
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
        $this->bitfield = $this->bitfield & ~(255 << self::BITFIELD_DISABLED);
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

        switch ($this->getReason()) {
            case self::RULE_INTERNAL_ALLOW_UPDATE:
                return $ruleText;

            case self::RULE_JOB_INSTALL:
                return "Install command rule ($ruleText)";

            case self::RULE_JOB_REMOVE:
                return "Remove command rule ($ruleText)";

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

                    if ($targetName === 'php' || $targetName === 'php-64bit' || $targetName === 'hhvm') {
                        // handle php/hhvm
                        if (defined('HHVM_VERSION')) {
                            return $text . ' -> your HHVM version does not satisfy that requirement.';
                        }

                        if ($targetName === 'hhvm') {
                            return $text . ' -> you are running this with PHP and not HHVM.';
                        }

                        $packages = $pool->whatProvides($targetName);
                        $package = count($packages) ? current($packages) : phpversion();

                        if (!($package instanceof CompletePackage)) {
                            return $text . ' -> your PHP version ('.phpversion().') does not satisfy that requirement.';
                        }

                        $extra = $package->getExtra();

                        if (!empty($extra['config.platform'])) {
                            $text .= ' -> your PHP version ('.phpversion().') overridden by "config.platform.php" version ('.$package->getPrettyVersion().') does not satisfy that requirement.';
                        } else {
                            $text .= ' -> your PHP version ('.$package->getPrettyVersion().') does not satisfy that requirement.';
                        }

                        return $text;
                    }

                    if (0 === strpos($targetName, 'ext-')) {
                        // handle php extensions
                        $ext = substr($targetName, 4);
                        $error = extension_loaded($ext) ? 'has the wrong version ('.(phpversion($ext) ?: '0').') installed' : 'is missing from your system';

                        return $text . ' -> the requested PHP extension '.$ext.' '.$error.'.';
                    }

                    if (0 === strpos($targetName, 'lib-')) {
                        // handle linked libs
                        $lib = substr($targetName, 4);

                        return $text . ' -> the requested linked library '.$lib.' has the wrong version installed or is missing from your system, make sure to have the extension providing it.';
                    }

                    if ($providers = $pool->whatProvides($targetName, $this->reasonData->getConstraint(), true, true)) {
                        return $text . ' -> satisfiable by ' . $this->formatPackagesUnique($pool, $providers) .' but these conflict with your requirements or minimum-stability.';
                    }

                    return $text . ' -> no matching package found.';
                }

                return $text;

            case self::RULE_PACKAGE_OBSOLETES:
                return $ruleText;
            case self::RULE_INSTALLED_PACKAGE_OBSOLETES:
                return $ruleText;
            case self::RULE_PACKAGE_SAME_NAME:
                return 'Can only install one of: ' . $this->formatPackagesUnique($pool, $literals) . '.';
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
}
