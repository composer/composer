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

use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\Link;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositorySet;
use Composer\Package\Version\VersionParser;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;

/**
 * @author Nils Adermann <naderman@naderman.de>
 * @author Ruben Gonzalez <rubenrua@gmail.com>
 * @phpstan-type ReasonData Link|BasePackage|string|int|array{packageName: string, constraint: ConstraintInterface}|array{package: BasePackage}
 */
abstract class Rule
{
    // reason constants and // their reason data contents
    public const RULE_ROOT_REQUIRE = 2; // array{packageName: string, constraint: ConstraintInterface}
    public const RULE_FIXED = 3; // array{package: BasePackage}
    public const RULE_PACKAGE_CONFLICT = 6; // Link
    public const RULE_PACKAGE_REQUIRES = 7; // Link
    public const RULE_PACKAGE_SAME_NAME = 10; // string (package name)
    public const RULE_LEARNED = 12; // int (rule id)
    public const RULE_PACKAGE_ALIAS = 13; // BasePackage
    public const RULE_PACKAGE_INVERSE_ALIAS = 14; // BasePackage

    // bitfield defs
    private const BITFIELD_TYPE = 0;
    private const BITFIELD_REASON = 8;
    private const BITFIELD_DISABLED = 16;

    /** @var int */
    protected $bitfield;
    /** @var Request */
    protected $request;
    /**
     * @var Link|BasePackage|ConstraintInterface|string
     * @phpstan-var ReasonData
     */
    protected $reasonData;

    /**
     * @param self::RULE_* $reason     A RULE_* constant describing the reason for generating this rule
     * @param mixed        $reasonData
     *
     * @phpstan-param ReasonData $reasonData
     */
    public function __construct($reason, $reasonData)
    {
        $this->reasonData = $reasonData;

        $this->bitfield = (0 << self::BITFIELD_DISABLED) |
            ($reason << self::BITFIELD_REASON) |
            (255 << self::BITFIELD_TYPE);
    }

    /**
     * @return int[]
     */
    abstract public function getLiterals(): array;

    /**
     * @return int|string
     */
    abstract public function getHash();

    abstract public function __toString(): string;

    /**
     * @param Rule $rule
     * @return bool
     */
    abstract public function equals(Rule $rule): bool;

    /**
     * @return int
     */
    public function getReason(): int
    {
        return ($this->bitfield & (255 << self::BITFIELD_REASON)) >> self::BITFIELD_REASON;
    }

    /**
     * @phpstan-return ReasonData
     */
    public function getReasonData()
    {
        return $this->reasonData;
    }

    /**
     * @return string|null
     */
    public function getRequiredPackage(): ?string
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

        return null;
    }

    /**
     * @param RuleSet::TYPE_* $type
     * @return void
     */
    public function setType($type): void
    {
        $this->bitfield = ($this->bitfield & ~(255 << self::BITFIELD_TYPE)) | ((255 & $type) << self::BITFIELD_TYPE);
    }

    /**
     * @return int
     */
    public function getType(): int
    {
        return ($this->bitfield & (255 << self::BITFIELD_TYPE)) >> self::BITFIELD_TYPE;
    }

    /**
     * @return void
     */
    public function disable(): void
    {
        $this->bitfield = ($this->bitfield & ~(255 << self::BITFIELD_DISABLED)) | (1 << self::BITFIELD_DISABLED);
    }

    /**
     * @return void
     */
    public function enable(): void
    {
        $this->bitfield &= ~(255 << self::BITFIELD_DISABLED);
    }

    /**
     * @return bool
     */
    public function isDisabled(): bool
    {
        return (bool) (($this->bitfield & (255 << self::BITFIELD_DISABLED)) >> self::BITFIELD_DISABLED);
    }

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return !(($this->bitfield & (255 << self::BITFIELD_DISABLED)) >> self::BITFIELD_DISABLED);
    }

    /**
     * @return bool
     */
    abstract public function isAssertion(): bool;

    /**
     * @return bool
     */
    public function isCausedByLock(RepositorySet $repositorySet, Request $request, Pool $pool): bool
    {
        if ($this->getReason() === self::RULE_PACKAGE_REQUIRES) {
            if (PlatformRepository::isPlatformPackage($this->reasonData->getTarget())) {
                return false;
            }
            if ($request->getLockedRepository()) {
                foreach ($request->getLockedRepository()->getPackages() as $package) {
                    if ($package->getName() === $this->reasonData->getTarget()) {
                        if ($pool->isUnacceptableFixedOrLockedPackage($package)) {
                            return true;
                        }
                        if (!$this->reasonData->getConstraint()->matches(new Constraint('=', $package->getVersion()))) {
                            return true;
                        }
                        // required package was locked but has been unlocked and still matches
                        if (!$request->isLockedPackage($package)) {
                            return true;
                        }
                        break;
                    }
                }
            }
        }

        if ($this->getReason() === self::RULE_ROOT_REQUIRE) {
            if (PlatformRepository::isPlatformPackage($this->reasonData['packageName'])) {
                return false;
            }
            if ($request->getLockedRepository()) {
                foreach ($request->getLockedRepository()->getPackages() as $package) {
                    if ($package->getName() === $this->reasonData['packageName']) {
                        if ($pool->isUnacceptableFixedOrLockedPackage($package)) {
                            return true;
                        }
                        if (!$this->reasonData['constraint']->matches(new Constraint('=', $package->getVersion()))) {
                            return true;
                        }
                        break;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @internal
     * @return BasePackage
     */
    public function getSourcePackage(Pool $pool): BasePackage
    {
        $literals = $this->getLiterals();

        switch ($this->getReason()) {
            case self::RULE_PACKAGE_CONFLICT:
                $package1 = $this->deduplicateDefaultBranchAlias($pool->literalToPackage($literals[0]));
                $package2 = $this->deduplicateDefaultBranchAlias($pool->literalToPackage($literals[1]));

                if ($reasonData = $this->getReasonData()) {
                    // swap literals if they are not in the right order with package2 being the conflicter
                    if ($reasonData->getSource() === $package1->getName()) {
                        list($package2, $package1) = array($package1, $package2);
                    }
                }

                return $package2;

            case self::RULE_PACKAGE_REQUIRES:
                $sourceLiteral = array_shift($literals);
                $sourcePackage = $this->deduplicateDefaultBranchAlias($pool->literalToPackage($sourceLiteral));

                return $sourcePackage;

            default:
                throw new \LogicException('Not implemented');
        }
    }

    /**
     * @param bool $isVerbose
     * @param BasePackage[] $installedMap
     * @param array<Rule[]> $learnedPool
     * @return string
     */
    public function getPrettyString(RepositorySet $repositorySet, Request $request, Pool $pool, bool $isVerbose, array $installedMap = array(), array $learnedPool = array()): string
    {
        $literals = $this->getLiterals();

        switch ($this->getReason()) {
            case self::RULE_ROOT_REQUIRE:
                $packageName = $this->reasonData['packageName'];
                $constraint = $this->reasonData['constraint'];

                $packages = $pool->whatProvides($packageName, $constraint);
                if (!$packages) {
                    return 'No package found to satisfy root composer.json require '.$packageName.($constraint ? ' '.$constraint->getPrettyString() : '');
                }

                $packagesNonAlias = array_values(array_filter($packages, function ($p): bool {
                    return !($p instanceof AliasPackage);
                }));
                if (count($packagesNonAlias) === 1) {
                    $package = $packagesNonAlias[0];
                    if ($request->isLockedPackage($package)) {
                        return $package->getPrettyName().' is locked to version '.$package->getPrettyVersion()." and an update of this package was not requested.";
                    }
                }

                return 'Root composer.json requires '.$packageName.($constraint ? ' '.$constraint->getPrettyString() : '').' -> satisfiable by '.$this->formatPackagesUnique($pool, $packages, $isVerbose, $constraint).'.';

            case self::RULE_FIXED:
                $package = $this->deduplicateDefaultBranchAlias($this->reasonData['package']);

                if ($request->isLockedPackage($package)) {
                    return $package->getPrettyName().' is locked to version '.$package->getPrettyVersion().' and an update of this package was not requested.';
                }

                return $package->getPrettyName().' is present at version '.$package->getPrettyVersion() . ' and cannot be modified by Composer';

            case self::RULE_PACKAGE_CONFLICT:
                $package1 = $this->deduplicateDefaultBranchAlias($pool->literalToPackage($literals[0]));
                $package2 = $this->deduplicateDefaultBranchAlias($pool->literalToPackage($literals[1]));

                $conflictTarget = $package1->getPrettyString();
                if ($reasonData = $this->getReasonData()) {
                    assert($reasonData instanceof Link);

                    // swap literals if they are not in the right order with package2 being the conflicter
                    if ($reasonData->getSource() === $package1->getName()) {
                        list($package2, $package1) = array($package1, $package2);
                        $conflictTarget = $package1->getPrettyName().' '.$reasonData->getPrettyConstraint();
                    }

                    // if the conflict is not directly against the package but something it provides/replaces,
                    // we try to find that link to display a better message
                    if ($reasonData->getTarget() !== $package1->getName()) {
                        $provideType = null;
                        $provided = null;
                        foreach ($package1->getProvides() as $provide) {
                            if ($provide->getTarget() === $reasonData->getTarget()) {
                                $provideType = 'provides';
                                $provided = $provide->getPrettyConstraint();
                                break;
                            }
                        }
                        foreach ($package1->getReplaces() as $replace) {
                            if ($replace->getTarget() === $reasonData->getTarget()) {
                                $provideType = 'replaces';
                                $provided = $replace->getPrettyConstraint();
                                break;
                            }
                        }
                        if (null !== $provideType) {
                            $conflictTarget = $reasonData->getTarget().' '.$reasonData->getPrettyConstraint().' ('.$package1->getPrettyString().' '.$provideType.' '.$reasonData->getTarget().' '.$provided.')';
                        }
                    }
                }

                return $package2->getPrettyString().' conflicts with '.$conflictTarget.'.';

            case self::RULE_PACKAGE_REQUIRES:
                $sourceLiteral = array_shift($literals);
                $sourcePackage = $this->deduplicateDefaultBranchAlias($pool->literalToPackage($sourceLiteral));
                /** @var Link */
                $reasonData = $this->reasonData;

                $requires = array();
                foreach ($literals as $literal) {
                    $requires[] = $pool->literalToPackage($literal);
                }

                $text = $reasonData->getPrettyString($sourcePackage);
                if ($requires) {
                    $text .= ' -> satisfiable by ' . $this->formatPackagesUnique($pool, $requires, $isVerbose, $this->reasonData->getConstraint()) . '.';
                } else {
                    $targetName = $reasonData->getTarget();

                    $reason = Problem::getMissingPackageReason($repositorySet, $request, $pool, $isVerbose, $targetName, $this->reasonData->getConstraint());

                    return $text . ' -> ' . $reason[1];
                }

                return $text;

            case self::RULE_PACKAGE_SAME_NAME:
                $packageNames = array();
                foreach ($literals as $literal) {
                    $package = $pool->literalToPackage($literal);
                    $packageNames[$package->getName()] = true;
                }
                /** @var string $replacedName */
                $replacedName = $this->reasonData;

                if (count($packageNames) > 1) {
                    $reason = null;

                    if (!isset($packageNames[$replacedName])) {
                        $reason = 'They '.(count($literals) == 2 ? 'both' : 'all').' replace '.$replacedName.' and thus cannot coexist.';
                    } else {
                        $replacerNames = $packageNames;
                        unset($replacerNames[$replacedName]);
                        $replacerNames = array_keys($replacerNames);

                        if (count($replacerNames) == 1) {
                            $reason = $replacerNames[0] . ' replaces ';
                        } else {
                            $reason = '['.implode(', ', $replacerNames).'] replace ';
                        }
                        $reason .= $replacedName.' and thus cannot coexist with it.';
                    }

                    $installedPackages = array();
                    $removablePackages = array();
                    foreach ($literals as $literal) {
                        if (isset($installedMap[abs($literal)])) {
                            $installedPackages[] = $pool->literalToPackage($literal);
                        } else {
                            $removablePackages[] = $pool->literalToPackage($literal);
                        }
                    }

                    if ($installedPackages && $removablePackages) {
                        return $this->formatPackagesUnique($pool, $removablePackages, $isVerbose, null, true).' cannot be installed as that would require removing '.$this->formatPackagesUnique($pool, $installedPackages, $isVerbose, null, true).'. '.$reason;
                    }

                    return 'Only one of these can be installed: '.$this->formatPackagesUnique($pool, $literals, $isVerbose, null, true).'. '.$reason;
                }

                return 'You can only install one version of a package, so only one of these can be installed: ' . $this->formatPackagesUnique($pool, $literals, $isVerbose, null, true) . '.';
            case self::RULE_LEARNED:
                /** @TODO currently still generates way too much output to be helpful, and in some cases can even lead to endless recursion */
                // if (isset($learnedPool[$this->reasonData])) {
                //     echo $this->reasonData."\n";
                //     $learnedString = ', learned rules:' . Problem::formatDeduplicatedRules($learnedPool[$this->reasonData], '        ', $repositorySet, $request, $pool, $isVerbose, $installedMap, $learnedPool);
                // } else {
                //     $learnedString = ' (reasoning unavailable)';
                // }
                $learnedString = ' (conflict analysis result)';

                if (count($literals) === 1) {
                    $ruleText = $pool->literalToPrettyString($literals[0], $installedMap);
                } else {
                    $groups = array();
                    foreach ($literals as $literal) {
                        $package = $pool->literalToPackage($literal);
                        if (isset($installedMap[$package->id])) {
                            $group = $literal > 0 ? 'keep' : 'remove';
                        } else {
                            $group = $literal > 0 ? 'install' : 'don\'t install';
                        }

                        $groups[$group][] = $this->deduplicateDefaultBranchAlias($package);
                    }
                    $ruleTexts = array();
                    foreach ($groups as $group => $packages) {
                        $ruleTexts[] = $group . (count($packages) > 1 ? ' one of' : '').' ' . $this->formatPackagesUnique($pool, $packages, $isVerbose);
                    }

                    $ruleText = implode(' | ', $ruleTexts);
                }

                return 'Conclusion: '.$ruleText.$learnedString;
            case self::RULE_PACKAGE_ALIAS:
                $aliasPackage = $pool->literalToPackage($literals[0]);

                // avoid returning content like "9999999-dev is an alias of dev-master" as it is useless
                if ($aliasPackage->getVersion() === VersionParser::DEFAULT_BRANCH_ALIAS) {
                    return '';
                }
                $package = $this->deduplicateDefaultBranchAlias($pool->literalToPackage($literals[1]));

                return $aliasPackage->getPrettyString() .' is an alias of '.$package->getPrettyString().' and thus requires it to be installed too.';
            case self::RULE_PACKAGE_INVERSE_ALIAS:
                // inverse alias rules work the other way around than above
                $aliasPackage = $pool->literalToPackage($literals[1]);

                // avoid returning content like "9999999-dev is an alias of dev-master" as it is useless
                if ($aliasPackage->getVersion() === VersionParser::DEFAULT_BRANCH_ALIAS) {
                    return '';
                }
                $package = $this->deduplicateDefaultBranchAlias($pool->literalToPackage($literals[0]));

                return $aliasPackage->getPrettyString() .' is an alias of '.$package->getPrettyString().' and must be installed with it.';
            default:
                $ruleText = '';
                foreach ($literals as $i => $literal) {
                    if ($i != 0) {
                        $ruleText .= '|';
                    }
                    $ruleText .= $pool->literalToPrettyString($literal, $installedMap);
                }

                return '('.$ruleText.')';
        }
    }

    /**
     * @param array<int|BasePackage> $packages An array containing packages or literals
     * @param bool $isVerbose
     * @param bool $useRemovedVersionGroup
     * @return string
     */
    protected function formatPackagesUnique(Pool $pool, array $packages, bool $isVerbose, ConstraintInterface $constraint = null, bool $useRemovedVersionGroup = false): string
    {
        foreach ($packages as $index => $package) {
            if (!\is_object($package)) {
                $packages[$index] = $pool->literalToPackage($package);
            }
        }

        return Problem::getPackageList($packages, $isVerbose, $pool, $constraint, $useRemovedVersionGroup);
    }

    /**
     * @return BasePackage
     */
    private function deduplicateDefaultBranchAlias(BasePackage $package): BasePackage
    {
        if ($package instanceof AliasPackage && $package->getPrettyVersion() === VersionParser::DEFAULT_BRANCH_ALIAS) {
            $package = $package->getAliasOf();
        }

        return $package;
    }
}
