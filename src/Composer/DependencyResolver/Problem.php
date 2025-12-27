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

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Advisory\SecurityAdvisory;
use Composer\Package\CompletePackageInterface;
use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Pcre\Preg;
use Composer\Repository\RepositorySet;
use Composer\Repository\LockArrayRepository;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Package\Version\VersionParser;
use Composer\Repository\PlatformRepository;
use Composer\Semver\Constraint\MultiConstraint;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Represents a problem detected while solving dependencies
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class Problem
{
    /**
     * A map containing the id of each rule part of this problem as a key
     * @var array<string, true>
     */
    protected $reasonSeen;

    /**
     * A set of reasons for the problem, each is a rule or a root require and a rule
     * @var array<int, array<int, Rule>>
     */
    protected $reasons = [];

    /** @var int */
    protected $section = 0;

    /**
     * Add a rule as a reason
     *
     * @param Rule $rule A rule which is a reason for this problem
     */
    public function addRule(Rule $rule): void
    {
        $this->addReason(spl_object_hash($rule), $rule);
    }

    /**
     * Retrieve all reasons for this problem
     *
     * @return array<int, array<int, Rule>> The problem's reasons
     */
    public function getReasons(): array
    {
        return $this->reasons;
    }

    /**
     * A human readable textual representation of the problem's reasons
     *
     * @param array<int|string, BasePackage> $installedMap A map of all present packages
     * @param array<Rule[]> $learnedPool
     */
    public function getPrettyString(RepositorySet $repositorySet, Request $request, Pool $pool, bool $isVerbose, array $installedMap = [], array $learnedPool = []): string
    {
        // TODO doesn't this entirely defeat the purpose of the problem sections? what's the point of sections?
        $reasons = array_merge(...array_reverse($this->reasons));

        if (\count($reasons) === 1) {
            reset($reasons);
            $rule = current($reasons);

            if ($rule->getReason() !== Rule::RULE_ROOT_REQUIRE) {
                throw new \LogicException("Single reason problems must contain a root require rule.");
            }

            $reasonData = $rule->getReasonData();
            $packageName = $reasonData['packageName'];
            $constraint = $reasonData['constraint'];

            $packages = $pool->whatProvides($packageName, $constraint);
            if (\count($packages) === 0) {
                return "\n    ".implode(self::getMissingPackageReason($repositorySet, $request, $pool, $isVerbose, $packageName, $constraint));
            }
        }

        usort($reasons, function (Rule $rule1, Rule $rule2) use ($pool) {
            $rule1Prio = $this->getRulePriority($rule1);
            $rule2Prio = $this->getRulePriority($rule2);
            if ($rule1Prio !== $rule2Prio) {
                return $rule2Prio - $rule1Prio;
            }

            return $this->getSortableString($pool, $rule1) <=> $this->getSortableString($pool, $rule2);
        });

        return self::formatDeduplicatedRules($reasons, '    ', $repositorySet, $request, $pool, $isVerbose, $installedMap, $learnedPool);
    }

    private function getSortableString(Pool $pool, Rule $rule): string
    {
        switch ($rule->getReason()) {
            case Rule::RULE_ROOT_REQUIRE:
                return $rule->getReasonData()['packageName'];
            case Rule::RULE_FIXED:
                return (string) $rule->getReasonData()['package'];
            case Rule::RULE_PACKAGE_CONFLICT:
            case Rule::RULE_PACKAGE_REQUIRES:
                return $rule->getSourcePackage($pool) . '//' . $rule->getReasonData()->getPrettyString($rule->getSourcePackage($pool));
            case Rule::RULE_PACKAGE_SAME_NAME:
            case Rule::RULE_PACKAGE_ALIAS:
            case Rule::RULE_PACKAGE_INVERSE_ALIAS:
                return (string) $rule->getReasonData();
            case Rule::RULE_LEARNED:
                return implode('-', $rule->getLiterals());
        }

        // @phpstan-ignore deadCode.unreachable
        throw new \LogicException('Unknown rule type: '.$rule->getReason());
    }

    private function getRulePriority(Rule $rule): int
    {
        switch ($rule->getReason()) {
            case Rule::RULE_FIXED:
                return 3;
            case Rule::RULE_ROOT_REQUIRE:
                return 2;
            case Rule::RULE_PACKAGE_CONFLICT:
            case Rule::RULE_PACKAGE_REQUIRES:
                return 1;
            case Rule::RULE_PACKAGE_SAME_NAME:
            case Rule::RULE_LEARNED:
            case Rule::RULE_PACKAGE_ALIAS:
            case Rule::RULE_PACKAGE_INVERSE_ALIAS:
                return 0;
        }

        // @phpstan-ignore deadCode.unreachable
        throw new \LogicException('Unknown rule type: '.$rule->getReason());
    }

    /**
     * @param Rule[] $rules
     * @param array<int|string, BasePackage> $installedMap A map of all present packages
     * @param array<Rule[]> $learnedPool
     * @internal
     */
    public static function formatDeduplicatedRules(array $rules, string $indent, RepositorySet $repositorySet, Request $request, Pool $pool, bool $isVerbose, array $installedMap = [], array $learnedPool = []): string
    {
        $messages = [];
        $templates = [];
        $parser = new VersionParser;
        $deduplicatableRuleTypes = [Rule::RULE_PACKAGE_REQUIRES, Rule::RULE_PACKAGE_CONFLICT];
        foreach ($rules as $rule) {
            $message = $rule->getPrettyString($repositorySet, $request, $pool, $isVerbose, $installedMap, $learnedPool);
            if (in_array($rule->getReason(), $deduplicatableRuleTypes, true) && Preg::isMatchStrictGroups('{^(?P<package>\S+) (?P<version>\S+) (?P<type>requires|conflicts)}', $message, $m)) {
                $message = str_replace('%', '%%', $message);
                $template = Preg::replace('{^\S+ \S+ }', '%s%s ', $message);
                $messages[] = $template;
                $templates[$template][$m[1]][$parser->normalize($m[2])] = $m[2];
                $sourcePackage = $rule->getSourcePackage($pool);
                foreach ($pool->getRemovedVersionsByPackage(spl_object_hash($sourcePackage)) as $version => $prettyVersion) {
                    $templates[$template][$m[1]][$version] = $prettyVersion;
                }
            } elseif ($message !== '') {
                $messages[] = $message;
            }
        }

        $result = [];
        foreach (array_unique($messages) as $message) {
            if (isset($templates[$message])) {
                foreach ($templates[$message] as $package => $versions) {
                    uksort($versions, 'version_compare');
                    if (!$isVerbose) {
                        $versions = self::condenseVersionList($versions, 1);
                    }
                    if (\count($versions) > 1) {
                        // remove the s from requires/conflicts to correct grammar
                        $message = Preg::replace('{^(%s%s (?:require|conflict))s}', '$1', $message);
                        $result[] = sprintf($message, $package, '['.implode(', ', $versions).']');
                    } else {
                        $result[] = sprintf($message, $package, ' '.reset($versions));
                    }
                }
            } else {
                $result[] = $message;
            }
        }

        return "\n$indent- ".implode("\n$indent- ", $result);
    }

    public function isCausedByLock(RepositorySet $repositorySet, Request $request, Pool $pool): bool
    {
        foreach ($this->reasons as $sectionRules) {
            foreach ($sectionRules as $rule) {
                if ($rule->isCausedByLock($repositorySet, $request, $pool)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Store a reason descriptor but ignore duplicates
     *
     * @param string $id     A canonical identifier for the reason
     * @param Rule   $reason The reason descriptor
     */
    protected function addReason(string $id, Rule $reason): void
    {
        // TODO: if a rule is part of a problem description in two sections, isn't this going to remove a message
        // that is important to understand the issue?

        if (!isset($this->reasonSeen[$id])) {
            $this->reasonSeen[$id] = true;
            $this->reasons[$this->section][] = $reason;
        }
    }

    public function nextSection(): void
    {
        $this->section++;
    }

    /**
     * @internal
     * @return array{0: string, 1: string}
     */
    public static function getMissingPackageReason(RepositorySet $repositorySet, Request $request, Pool $pool, bool $isVerbose, string $packageName, ?ConstraintInterface $constraint = null): array
    {
        if (PlatformRepository::isPlatformPackage($packageName)) {
            // handle php/php-*/hhvm
            if (0 === stripos($packageName, 'php') || $packageName === 'hhvm') {
                $version = self::getPlatformPackageVersion($pool, $packageName, phpversion());

                $msg = "- Root composer.json requires ".$packageName.self::constraintToText($constraint).' but ';

                if (defined('HHVM_VERSION') || ($packageName === 'hhvm' && count($pool->whatProvides($packageName)) > 0)) {
                    return [$msg, 'your HHVM version does not satisfy that requirement.'];
                }

                if ($packageName === 'hhvm') {
                    return [$msg, 'HHVM was not detected on this machine, make sure it is in your PATH.'];
                }

                if (null === $version) {
                    return [$msg, 'the '.$packageName.' package is disabled by your platform config. Enable it again with "composer config platform.'.$packageName.' --unset".'];
                }

                return [$msg, 'your '.$packageName.' version ('. $version .') does not satisfy that requirement.'];
            }

            // handle php extensions
            if (0 === stripos($packageName, 'ext-')) {
                if (false !== strpos($packageName, ' ')) {
                    return ['- ', "PHP extension ".$packageName.' should be required as '.str_replace(' ', '-', $packageName).'.'];
                }

                $ext = substr($packageName, 4);
                $msg = "- Root composer.json requires PHP extension ".$packageName.self::constraintToText($constraint).' but ';

                $version = self::getPlatformPackageVersion($pool, $packageName, phpversion($ext) === false ? '0' : phpversion($ext));
                if (null === $version) {
                    $providersStr = self::getProvidersList($repositorySet, $packageName, 5);
                    if ($providersStr !== null) {
                        $providersStr = "\n\n      Alternatively you can require one of these packages that provide the extension (or parts of it):\n".
                            "      <warning>Keep in mind that the suggestions are automated and may not be valid or safe to use</warning>\n$providersStr";
                    }

                    if (extension_loaded($ext)) {
                        return [
                            $msg,
                            'the '.$packageName.' package is disabled by your platform config. Enable it again with "composer config platform.'.$packageName.' --unset".' . $providersStr,
                        ];
                    }

                    return [$msg, 'it is missing from your system. Install or enable PHP\'s '.$ext.' extension.' . $providersStr];
                }

                return [$msg, 'it has the wrong version installed ('.$version.').'];
            }

            // handle linked libs
            if (0 === stripos($packageName, 'lib-')) {
                if (strtolower($packageName) === 'lib-icu') {
                    $error = extension_loaded('intl') ? 'it has the wrong version installed, try upgrading the intl extension.' : 'it is missing from your system, make sure the intl extension is loaded.';

                    return ["- Root composer.json requires linked library ".$packageName.self::constraintToText($constraint).' but ', $error];
                }

                $providersStr = self::getProvidersList($repositorySet, $packageName, 5);
                if ($providersStr !== null) {
                    $providersStr = "\n\n      Alternatively you can require one of these packages that provide the library (or parts of it):\n".
                    "      <warning>Keep in mind that the suggestions are automated and may not be valid or safe to use</warning>\n$providersStr";
                }

                return ["- Root composer.json requires linked library ".$packageName.self::constraintToText($constraint).' but ', 'it has the wrong version installed or is missing from your system, make sure to load the extension providing it.'.$providersStr];
            }
        }

        $lockedPackage = null;
        foreach ($request->getLockedPackages() as $package) {
            if ($package->getName() === $packageName) {
                $lockedPackage = $package;
                if ($pool->isUnacceptableFixedOrLockedPackage($package)) {
                    return ["- ", $package->getPrettyName().' is fixed to '.$package->getPrettyVersion().' (lock file version) by a partial update but that version is rejected by your minimum-stability. Make sure you list it as an argument for the update command.'];
                }
                break;
            }
        }

        if ($constraint instanceof Constraint && $constraint->getOperator() === Constraint::STR_OP_EQ && Preg::isMatch('{^dev-.*#.*}', $constraint->getPrettyString())) {
            $newConstraint = Preg::replace('{ +as +([^,\s|]+)$}', '', $constraint->getPrettyString());
            $packages = $repositorySet->findPackages($packageName, new MultiConstraint([
                new Constraint(Constraint::STR_OP_EQ, $newConstraint),
                new Constraint(Constraint::STR_OP_EQ, str_replace('#', '+', $newConstraint)),
            ], false));
            if (\count($packages) > 0) {
                return ["- Root composer.json requires $packageName".self::constraintToText($constraint) . ', ', 'found '.self::getPackageList($packages, $isVerbose, $pool, $constraint).'. The # character in branch names is replaced by a + character. Make sure to require it as "'.str_replace('#', '+', $constraint->getPrettyString()).'".'];
            }
        }

        // first check if the actual requested package is found in normal conditions
        // if so it must mean it is rejected by another constraint than the one given here
        $packages = $repositorySet->findPackages($packageName, $constraint);
        if (\count($packages) > 0) {
            $rootReqs = $repositorySet->getRootRequires();
            if (isset($rootReqs[$packageName])) {
                $filtered = array_filter($packages, static function ($p) use ($rootReqs, $packageName): bool {
                    return $rootReqs[$packageName]->matches(new Constraint('==', $p->getVersion()));
                });
                if (0 === count($filtered)) {
                    return ["- Root composer.json requires $packageName".self::constraintToText($constraint) . ', ', 'found '.self::getPackageList($packages, $isVerbose, $pool, $constraint).' but '.(self::hasMultipleNames($packages) ? 'these conflict' : 'it conflicts').' with your root composer.json require ('.$rootReqs[$packageName]->getPrettyString().').'];
                }
            }

            $tempReqs = $repositorySet->getTemporaryConstraints();
            foreach (reset($packages)->getNames() as $name) {
                if (isset($tempReqs[$name])) {
                    $filtered = array_filter($packages, static function ($p) use ($tempReqs, $name): bool {
                        return $tempReqs[$name]->matches(new Constraint('==', $p->getVersion()));
                    });
                    if (0 === count($filtered)) {
                        return ["- Root composer.json requires $name".self::constraintToText($constraint) . ', ', 'found '.self::getPackageList($packages, $isVerbose, $pool, $constraint).' but '.(self::hasMultipleNames($packages) ? 'these conflict' : 'it conflicts').' with your temporary update constraint ('.$name.':'.$tempReqs[$name]->getPrettyString().').'];
                    }
                }
            }

            if ($lockedPackage !== null) {
                $fixedConstraint = new Constraint('==', $lockedPackage->getVersion());
                $filtered = array_filter($packages, static function ($p) use ($fixedConstraint): bool {
                    return $fixedConstraint->matches(new Constraint('==', $p->getVersion()));
                });
                if (0 === count($filtered)) {
                    return ["- Root composer.json requires $packageName".self::constraintToText($constraint) . ', ', 'found '.self::getPackageList($packages, $isVerbose, $pool, $constraint).' but the package is fixed to '.$lockedPackage->getPrettyVersion().' (lock file version) by a partial update and that version does not match. Make sure you list it as an argument for the update command.'];
                }
            }

            $nonLockedPackages = array_filter($packages, static function ($p): bool {
                return !$p->getRepository() instanceof LockArrayRepository;
            });

            if (0 === \count($nonLockedPackages)) {
                return ["- Root composer.json requires $packageName".self::constraintToText($constraint) . ', ', 'found '.self::getPackageList($packages, $isVerbose, $pool, $constraint).' in the lock file but not in remote repositories, make sure you avoid updating this package to keep the one from the lock file.'];
            }

            if ($pool->isAbandonedRemovedPackageVersion($packageName, $constraint)) {
                return ["- Root composer.json requires $packageName".self::constraintToText($constraint) . ', ', 'found '.self::getPackageList($packages, $isVerbose, $pool, $constraint).' but these were not loaded, because they are abandoned and you configured "block-abandoned" to true in your "audit" config.'];
            }

            // Separate packages by their removal reason to provide accurate error messages
            $securityRemovedVersions = $pool->getAllSecurityRemovedPackageVersions()[$packageName] ?? [];
            $releaseAgeRemovedVersions = $pool->getAllReleaseAgeRemovedPackageVersions()[$packageName] ?? [];

            $securityPackages = array_filter($packages, static function ($p) use ($securityRemovedVersions): bool {
                return isset($securityRemovedVersions[$p->getVersion()]);
            });
            $releaseAgePackages = array_filter($packages, static function ($p) use ($releaseAgeRemovedVersions): bool {
                return isset($releaseAgeRemovedVersions[$p->getVersion()]);
            });

            $hasSecurityRemoved = \count($securityPackages) > 0;
            $hasReleaseAgeRemoved = \count($releaseAgePackages) > 0;

            // Handle combined case: both security and release-age filters removed versions
            if ($hasSecurityRemoved && $hasReleaseAgeRemoved) {
                $advisoriesList = self::getAdvisoriesListForPackages($securityPackages, $repositorySet, $pool, $packageName);
                $releaseAgeInfo = $pool->getReleaseAgeInfoForPackageVersion($packageName, $constraint);
                $timeInfo = $releaseAgeInfo !== null ? ' (available in '.$releaseAgeInfo['availableIn'].')' : '';

                // Don't pass pool to getPackageList to avoid adding all removed versions - we only want versions specific to each filter
                return ["- Root composer.json requires $packageName".self::constraintToText($constraint) . ', ', 'found '.self::getPackageList($securityPackages, $isVerbose).' but these were not loaded, because they are affected by security advisories ("' . implode('", "', $advisoriesList). '"). Go to https://packagist.org/security-advisories/ to find advisory details. Additionally, '.self::getPackageList($releaseAgePackages, $isVerbose).' were not loaded because they do not meet the minimum-release-age requirement'.$timeInfo.'. To resolve this, add the security advisories to the audit "ignore" config, or add the package to "minimum-release-age.exceptions", or wait for the newer versions to become available.'];
            }

            if ($hasSecurityRemoved) {
                $advisoriesList = self::getAdvisoriesListForPackages($securityPackages, $repositorySet, $pool, $packageName);

                return ["- Root composer.json requires $packageName".self::constraintToText($constraint) . ', ', 'found '.self::getPackageList($securityPackages, $isVerbose, $pool, $constraint).' but these were not loaded, because they are affected by security advisories ("' . implode('", "', $advisoriesList). '"). Go to https://packagist.org/security-advisories/ to find advisory details. To ignore the advisories, add them to the audit "ignore" config. To turn the feature off entirely, you can set "block-insecure" to false in your "audit" config.'];
            }

            if ($hasReleaseAgeRemoved) {
                $releaseAgeInfo = $pool->getReleaseAgeInfoForPackageVersion($packageName, $constraint);
                $timeInfo = $releaseAgeInfo !== null ? ' (available in '.$releaseAgeInfo['availableIn'].')' : '';
                return ["- Root composer.json requires $packageName".self::constraintToText($constraint) . ', ', 'found '.self::getPackageList($releaseAgePackages, $isVerbose, $pool, $constraint).' but these were not loaded because they do not meet the minimum-release-age requirement'.$timeInfo.'. To bypass this for specific packages, add them to "minimum-release-age.exceptions". To disable entirely, set "minimum-release-age.minimum-age" to null.'];
            }

            return ["- Root composer.json requires $packageName".self::constraintToText($constraint) . ', ', 'found '.self::getPackageList($packages, $isVerbose, $pool, $constraint).' but these were not loaded, likely because '.(self::hasMultipleNames($packages) ? 'they conflict' : 'it conflicts').' with another require.'];
        }

        // check if the package is found when bypassing stability checks
        $packages = $repositorySet->findPackages($packageName, $constraint, RepositorySet::ALLOW_UNACCEPTABLE_STABILITIES);
        if (\count($packages) > 0) {
            // we must first verify if a valid package would be found in a lower priority repository
            $allReposPackages = $repositorySet->findPackages($packageName, $constraint, RepositorySet::ALLOW_SHADOWED_REPOSITORIES);
            if (\count($allReposPackages) > 0) {
                return self::computeCheckForLowerPrioRepo($pool, $isVerbose, $packageName, $packages, $allReposPackages, 'minimum-stability', $constraint);
            }

            return ["- Root composer.json requires $packageName".self::constraintToText($constraint) . ', ', 'found '.self::getPackageList($packages, $isVerbose, $pool, $constraint).' but '.(self::hasMultipleNames($packages) ? 'these do' : 'it does').' not match your minimum-stability.'];
        }

        // check if the package is found when bypassing the constraint and stability checks
        $packages = $repositorySet->findPackages($packageName, null, RepositorySet::ALLOW_UNACCEPTABLE_STABILITIES);
        if (\count($packages) > 0) {
            // we must first verify if a valid package would be found in a lower priority repository
            $allReposPackages = $repositorySet->findPackages($packageName, $constraint, RepositorySet::ALLOW_SHADOWED_REPOSITORIES);
            if (\count($allReposPackages) > 0) {
                return self::computeCheckForLowerPrioRepo($pool, $isVerbose, $packageName, $packages, $allReposPackages, 'constraint', $constraint);
            }

            $suffix = '';
            if ($constraint instanceof Constraint && $constraint->getVersion() === 'dev-master') {
                foreach ($packages as $candidate) {
                    if (in_array($candidate->getVersion(), ['dev-default', 'dev-main'], true)) {
                        $suffix = ' Perhaps dev-master was renamed to '.$candidate->getPrettyVersion().'?';
                        break;
                    }
                }
            }

            // check if the root package is a name match and hint the dependencies on root troubleshooting article
            $allReposPackages = $packages;
            $topPackage = reset($allReposPackages);
            if ($topPackage instanceof RootPackageInterface) {
                $suffix = ' See https://getcomposer.org/dep-on-root for details and assistance.';
            }

            return ["- Root composer.json requires $packageName".self::constraintToText($constraint) . ', ', 'found '.self::getPackageList($packages, $isVerbose, $pool, $constraint).' but '.(self::hasMultipleNames($packages) ? 'these do' : 'it does').' not match the constraint.' . $suffix];
        }

        if (!Preg::isMatch('{^[A-Za-z0-9_./-]+$}', $packageName)) {
            $illegalChars = Preg::replace('{[A-Za-z0-9_./-]+}', '', $packageName);

            return ["- Root composer.json requires $packageName, it ", 'could not be found, it looks like its name is invalid, "'.$illegalChars.'" is not allowed in package names.'];
        }

        $providersStr = self::getProvidersList($repositorySet, $packageName, 15);
        if ($providersStr !== null) {
            return ["- Root composer.json requires $packageName".self::constraintToText($constraint).", it ", "could not be found in any version, but the following packages provide it:\n".$providersStr."      Consider requiring one of these to satisfy the $packageName requirement."];
        }

        return ["- Root composer.json requires $packageName, it ", "could not be found in any version, there may be a typo in the package name."];
    }

    /**
     * @internal
     * @param PackageInterface[] $packages
     */
    public static function getPackageList(array $packages, bool $isVerbose, ?Pool $pool = null, ?ConstraintInterface $constraint = null, bool $useRemovedVersionGroup = false): string
    {
        $prepared = [];
        $hasDefaultBranch = [];
        foreach ($packages as $package) {
            $prepared[$package->getName()]['name'] = $package->getPrettyName();
            $prepared[$package->getName()]['versions'][$package->getVersion()] = $package->getPrettyVersion().($package instanceof AliasPackage ? ' (alias of '.$package->getAliasOf()->getPrettyVersion().')' : '');
            if ($pool !== null && $constraint !== null) {
                foreach ($pool->getRemovedVersions($package->getName(), $constraint) as $version => $prettyVersion) {
                    $prepared[$package->getName()]['versions'][$version] = $prettyVersion;
                }
            }
            if ($pool !== null && $useRemovedVersionGroup) {
                foreach ($pool->getRemovedVersionsByPackage(spl_object_hash($package)) as $version => $prettyVersion) {
                    $prepared[$package->getName()]['versions'][$version] = $prettyVersion;
                }
            }
            if ($package->isDefaultBranch()) {
                $hasDefaultBranch[$package->getName()] = true;
            }
        }

        $preparedStrings = [];
        foreach ($prepared as $name => $package) {
            // remove the implicit default branch alias to avoid cruft in the display
            if (isset($package['versions'][VersionParser::DEFAULT_BRANCH_ALIAS], $hasDefaultBranch[$name])) {
                unset($package['versions'][VersionParser::DEFAULT_BRANCH_ALIAS]);
            }

            uksort($package['versions'], 'version_compare');

            if (!$isVerbose) {
                $package['versions'] = self::condenseVersionList($package['versions'], 4);
            }
            $preparedStrings[] = $package['name'].'['.implode(', ', $package['versions']).']';
        }

        return implode(', ', $preparedStrings);
    }

    /**
     * @param  string $version the effective runtime version of the platform package
     * @return ?string a version string or null if it appears the package was artificially disabled
     */
    private static function getPlatformPackageVersion(Pool $pool, string $packageName, string $version): ?string
    {
        $available = $pool->whatProvides($packageName);

        if (\count($available) > 0) {
            $selected = null;
            foreach ($available as $pkg) {
                if ($pkg->getRepository() instanceof PlatformRepository) {
                    $selected = $pkg;
                    break;
                }
            }
            if ($selected === null) {
                $selected = reset($available);
            }

            // must be a package providing/replacing and not a real platform package
            if ($selected->getName() !== $packageName) {
                /** @var Link $link */
                foreach (array_merge(array_values($selected->getProvides()), array_values($selected->getReplaces())) as $link) {
                    if ($link->getTarget() === $packageName) {
                        return $link->getPrettyConstraint().' '.substr($link->getDescription(), 0, -1).'d by '.$selected->getPrettyString();
                    }
                }
            }

            $version = $selected->getPrettyVersion();
            $extra = $selected->getExtra();
            if ($selected instanceof CompletePackageInterface && isset($extra['config.platform']) && $extra['config.platform'] === true) {
                $version .= '; ' . str_replace('Package ', '', (string) $selected->getDescription());
            }
        } else {
            return null;
        }

        return $version;
    }

    /**
     * @param array<string|int, string> $versions an array of pretty versions, with normalized versions as keys
     * @return list<string> a list of pretty versions and '...' where versions were removed
     */
    private static function condenseVersionList(array $versions, int $max, int $maxDev = 16): array
    {
        if (count($versions) <= $max) {
            return array_values($versions);
        }

        $filtered = [];
        $byMajor = [];
        foreach ($versions as $version => $pretty) {
            if (0 === stripos((string) $version, 'dev-')) {
                $byMajor['dev'][] = $pretty;
            } else {
                $byMajor[Preg::replace('{^(\d+)\..*}', '$1', (string) $version)][] = $pretty;
            }
        }
        foreach ($byMajor as $majorVersion => $versionsForMajor) {
            $maxVersions = $majorVersion === 'dev' ? $maxDev : $max;
            if (count($versionsForMajor) > $maxVersions) {
                // output only 1st and last versions
                $filtered[] = $versionsForMajor[0];
                $filtered[] = '...';
                $filtered[] = $versionsForMajor[count($versionsForMajor) - 1];
            } else {
                $filtered = array_merge($filtered, $versionsForMajor);
            }
        }

        return $filtered;
    }

    /**
     * @param PackageInterface[] $packages
     */
    private static function hasMultipleNames(array $packages): bool
    {
        $name = null;
        foreach ($packages as $package) {
            if ($name === null || $name === $package->getName()) {
                $name = $package->getName();
            } else {
                return true;
            }
        }

        return false;
    }

    /**
     * @param non-empty-array<PackageInterface> $higherRepoPackages
     * @param non-empty-array<PackageInterface> $allReposPackages
     * @return array{0: string, 1: string}
     */
    private static function computeCheckForLowerPrioRepo(Pool $pool, bool $isVerbose, string $packageName, array $higherRepoPackages, array $allReposPackages, string $reason, ?ConstraintInterface $constraint = null): array
    {
        $nextRepoPackages = [];
        $nextRepo = null;

        foreach ($allReposPackages as $package) {
            if ($nextRepo === null || $nextRepo === $package->getRepository()) {
                $nextRepoPackages[] = $package;
                $nextRepo = $package->getRepository();
            } else {
                break;
            }
        }

        assert(null !== $nextRepo);

        if (\count($higherRepoPackages) > 0) {
            $topPackage = reset($higherRepoPackages);
            if ($topPackage instanceof RootPackageInterface) {
                return [
                    "- Root composer.json requires $packageName".self::constraintToText($constraint).', it is ',
                    'satisfiable by '.self::getPackageList($nextRepoPackages, $isVerbose, $pool, $constraint).' from '.$nextRepo->getRepoName().' but '.$topPackage->getPrettyName().' '.$topPackage->getPrettyVersion().' is the root package and cannot be modified. See https://getcomposer.org/dep-on-root for details and assistance.',
                ];
            }
        }

        if ($nextRepo instanceof LockArrayRepository) {
            $singular = count($higherRepoPackages) === 1;

            $suggestion = 'Make sure you either fix the '.$reason.' or avoid updating this package to keep the one present in the lock file ('.self::getPackageList($nextRepoPackages, $isVerbose, $pool, $constraint).').';
            // symlinked path repos cannot be locked so do not suggest keeping it locked
            if ($nextRepoPackages[0]->getDistType() === 'path') {
                $transportOptions = $nextRepoPackages[0]->getTransportOptions();
                if (!isset($transportOptions['symlink']) || $transportOptions['symlink'] !== false) {
                    $suggestion = 'Make sure you fix the '.$reason.' as packages installed from symlinked path repos are updated even in partial updates and the one from the lock file can thus not be used.';
                }
            }

            return ["- Root composer.json requires $packageName".self::constraintToText($constraint) . ', ',
                'found ' . self::getPackageList($higherRepoPackages, $isVerbose, $pool, $constraint).' but ' . ($singular ? 'it does' : 'these do') . ' not match your '.$reason.' and ' . ($singular ? 'is' : 'are') . ' therefore not installable. '.$suggestion,
            ];
        }

        return ["- Root composer.json requires $packageName".self::constraintToText($constraint) . ', it is ', 'satisfiable by '.self::getPackageList($nextRepoPackages, $isVerbose, $pool, $constraint).' from '.$nextRepo->getRepoName().' but '.self::getPackageList($higherRepoPackages, $isVerbose, $pool, $constraint).' from '.reset($higherRepoPackages)->getRepository()->getRepoName().' has higher repository priority. The packages from the higher priority repository do not match your '.$reason.' and are therefore not installable. That repository is canonical so the lower priority repo\'s packages are not installable. See https://getcomposer.org/repoprio for details and assistance.'];
    }

    /**
     * Turns a constraint into text usable in a sentence describing a request
     */
    protected static function constraintToText(?ConstraintInterface $constraint = null): string
    {
        if ($constraint instanceof Constraint && $constraint->getOperator() === Constraint::STR_OP_EQ && !str_starts_with($constraint->getVersion(), 'dev-')) {
            if (!Preg::isMatch('{^\d+(?:\.\d+)*$}', $constraint->getPrettyString())) {
                return ' '.$constraint->getPrettyString() .' (exact version match)';
            }

            $versions = [$constraint->getPrettyString()];
            for ($i = 3 - substr_count($versions[0], '.'); $i > 0; $i--) {
                $versions[] = end($versions) . '.0';
            }

            return ' ' . $constraint->getPrettyString() . ' (exact version match: ' . (count($versions) > 1 ? implode(', ', array_slice($versions, 0, -1)) . ' or ' . end($versions) : $versions[0]) . ')';
        }

        return $constraint !== null ? ' '.$constraint->getPrettyString() : '';
    }

    /**
     * Get a list of formatted advisory identifiers for the given packages
     *
     * @param PackageInterface[] $packages
     * @return string[]
     */
    private static function getAdvisoriesListForPackages(array $packages, RepositorySet $repositorySet, Pool $pool, string $packageName): array
    {
        $advisories = $repositorySet->getMatchingSecurityAdvisories($packages, false, true);
        if (isset($advisories['advisories'][$packageName]) && \count($advisories['advisories'][$packageName]) > 0) {
            return array_map(static function (SecurityAdvisory $advisory): string {
                if ($advisory->link !== null && $advisory->link !== '') {
                    return '<href='.OutputFormatter::escape($advisory->link).'>'.$advisory->advisoryId.'</>';
                }

                if (str_starts_with($advisory->advisoryId, 'PKSA-')) {
                    return '<href='.OutputFormatter::escape('https://packagist.org/security-advisories/'.$advisory->advisoryId).'>'.$advisory->advisoryId.'</>';
                }

                return $advisory->advisoryId;
            }, $advisories['advisories'][$packageName]);
        }

        // Fallback: get advisory IDs from the pool's tracking
        $advisoryIds = [];
        $securityRemovedVersions = $pool->getAllSecurityRemovedPackageVersions()[$packageName] ?? [];
        foreach ($packages as $package) {
            $version = $package->getVersion();
            if (isset($securityRemovedVersions[$version])) {
                foreach ($securityRemovedVersions[$version] as $advisory) {
                    $advisoryId = $advisory->advisoryId;
                    if (!isset($advisoryIds[$advisoryId])) {
                        if (str_starts_with($advisoryId, 'PKSA-')) {
                            $advisoryIds[$advisoryId] = '<href='.OutputFormatter::escape('https://packagist.org/security-advisories/'.$advisoryId).'>'.$advisoryId.'</>';
                        } else {
                            $advisoryIds[$advisoryId] = $advisoryId;
                        }
                    }
                }
            }
        }

        return array_values($advisoryIds);
    }

    private static function getProvidersList(RepositorySet $repositorySet, string $packageName, int $maxProviders): ?string
    {
        $providers = $repositorySet->getProviders($packageName);
        if (\count($providers) > 0) {
            $providersStr = implode(array_map(static function ($p): string {
                $description = $p['description'] !== '' && $p['description'] !== null ? ' '.substr($p['description'], 0, 100) : '';

                return '      - '.$p['name'].$description."\n";
            }, count($providers) > $maxProviders + 1 ? array_slice($providers, 0, $maxProviders) : $providers));
            if (count($providers) > $maxProviders + 1) {
                $providersStr .= '      ... and '.(count($providers) - $maxProviders).' more.'."\n";
            }

            return $providersStr;
        }

        return null;
    }
}
