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

use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\PackageInterface;
use Composer\Semver\Constraint\Constraint;

/**
 * @author Nils Adermann <naderman@naderman.de>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class DefaultPolicy implements PolicyInterface
{
    /** @var bool */
    private $preferStable;
    /** @var bool */
    private $preferLowest;

    /**
     * @param bool $preferStable
     * @param bool $preferLowest
     */
    public function __construct($preferStable = false, $preferLowest = false)
    {
        $this->preferStable = $preferStable;
        $this->preferLowest = $preferLowest;
    }

    /**
     * @param string $operator One of Constraint::STR_OP_*
     * @return bool
     *
     * @phpstan-param Constraint::STR_OP_* $operator
     */
    public function versionCompare(PackageInterface $a, PackageInterface $b, $operator)
    {
        if ($this->preferStable && ($stabA = $a->getStability()) !== ($stabB = $b->getStability())) {
            return BasePackage::$stabilities[$stabA] < BasePackage::$stabilities[$stabB];
        }

        $constraint = new Constraint($operator, $b->getVersion());
        $version = new Constraint('==', $a->getVersion());

        return $constraint->matchSpecific($version, true);
    }

    /**
     * @param  int[]  $literals
     * @param  string $requiredPackage
     * @return int[]
     */
    public function selectPreferredPackages(Pool $pool, array $literals, $requiredPackage = null)
    {
        $packages = $this->groupLiteralsByName($pool, $literals);
        $policy = $this;

        foreach ($packages as &$nameLiterals) {
            usort($nameLiterals, function ($a, $b) use ($policy, $pool, $requiredPackage) {
                return $policy->compareByPriority($pool, $pool->literalToPackage($a), $pool->literalToPackage($b), $requiredPackage, true);
            });
        }

        foreach ($packages as &$sortedLiterals) {
            $sortedLiterals = $this->pruneToBestVersion($pool, $sortedLiterals);
            $sortedLiterals = $this->pruneRemoteAliases($pool, $sortedLiterals);
        }

        $selected = \call_user_func_array('array_merge', array_values($packages));

        // now sort the result across all packages to respect replaces across packages
        usort($selected, function ($a, $b) use ($policy, $pool, $requiredPackage) {
            return $policy->compareByPriority($pool, $pool->literalToPackage($a), $pool->literalToPackage($b), $requiredPackage);
        });

        return $selected;
    }

    /**
     * @param  int[] $literals
     * @return array<string, int[]>
     */
    protected function groupLiteralsByName(Pool $pool, $literals)
    {
        $packages = array();
        foreach ($literals as $literal) {
            $packageName = $pool->literalToPackage($literal)->getName();

            if (!isset($packages[$packageName])) {
                $packages[$packageName] = array();
            }
            $packages[$packageName][] = $literal;
        }

        return $packages;
    }

    /**
     * @protected
     * @param ?string $requiredPackage
     * @param bool $ignoreReplace
     * @return int
     */
    public function compareByPriority(Pool $pool, BasePackage $a, BasePackage $b, $requiredPackage = null, $ignoreReplace = false)
    {
        // prefer aliases to the original package
        if ($a->getName() === $b->getName()) {
            $aAliased = $a instanceof AliasPackage;
            $bAliased = $b instanceof AliasPackage;
            if ($aAliased && !$bAliased) {
                return -1; // use a
            }
            if (!$aAliased && $bAliased) {
                return 1; // use b
            }
        }

        if (!$ignoreReplace) {
            // return original, not replaced
            if ($this->replaces($a, $b)) {
                return 1; // use b
            }
            if ($this->replaces($b, $a)) {
                return -1; // use a
            }

            // for replacers not replacing each other, put a higher prio on replacing
            // packages with the same vendor as the required package
            if ($requiredPackage && false !== ($pos = strpos($requiredPackage, '/'))) {
                $requiredVendor = substr($requiredPackage, 0, $pos);

                $aIsSameVendor = strpos($a->getName(), $requiredVendor) === 0;
                $bIsSameVendor = strpos($b->getName(), $requiredVendor) === 0;

                if ($bIsSameVendor !== $aIsSameVendor) {
                    return $aIsSameVendor ? -1 : 1;
                }
            }
        }

        // priority equal, sort by package id to make reproducible
        if ($a->id === $b->id) {
            return 0;
        }

        return ($a->id < $b->id) ? -1 : 1;
    }

    /**
     * Checks if source replaces a package with the same name as target.
     *
     * Replace constraints are ignored. This method should only be used for
     * prioritisation, not for actual constraint verification.
     *
     * @return bool
     */
    protected function replaces(BasePackage $source, BasePackage $target)
    {
        foreach ($source->getReplaces() as $link) {
            if ($link->getTarget() === $target->getName()
//                && (null === $link->getConstraint() ||
//                $link->getConstraint()->matches(new Constraint('==', $target->getVersion())))) {
                ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  int[] $literals
     * @return int[]
     */
    protected function pruneToBestVersion(Pool $pool, $literals)
    {
        $operator = $this->preferLowest ? '<' : '>';
        $bestLiterals = array($literals[0]);
        $bestPackage = $pool->literalToPackage($literals[0]);
        foreach ($literals as $i => $literal) {
            if (0 === $i) {
                continue;
            }

            $package = $pool->literalToPackage($literal);

            if ($this->versionCompare($package, $bestPackage, $operator)) {
                $bestPackage = $package;
                $bestLiterals = array($literal);
            } elseif ($this->versionCompare($package, $bestPackage, '==')) {
                $bestLiterals[] = $literal;
            }
        }

        return $bestLiterals;
    }

    /**
     * Assumes that locally aliased (in root package requires) packages take priority over branch-alias ones
     *
     * If no package is a local alias, nothing happens
     *
     * @param  int[] $literals
     * @return int[]
     */
    protected function pruneRemoteAliases(Pool $pool, array $literals)
    {
        $hasLocalAlias = false;

        foreach ($literals as $literal) {
            $package = $pool->literalToPackage($literal);

            if ($package instanceof AliasPackage && $package->isRootPackageAlias()) {
                $hasLocalAlias = true;
                break;
            }
        }

        if (!$hasLocalAlias) {
            return $literals;
        }

        $selected = array();
        foreach ($literals as $literal) {
            $package = $pool->literalToPackage($literal);

            if ($package instanceof AliasPackage && $package->isRootPackageAlias()) {
                $selected[] = $literal;
            }
        }

        return $selected;
    }
}
