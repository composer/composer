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

        if (count($reasons) === 1) {
            reset($reasons);
            $rule = current($reasons);

            if ($rule->getReason() !== Rule::RULE_ROOT_REQUIRE) {
                throw new \LogicException("Single reason problems must contain a root require rule.");
            }

            $reasonData = $rule->getReasonData();
            $packageName = $reasonData['packageName'];
            $constraint = $reasonData['constraint'];

            $packages = $pool->whatProvides($packageName, $constraint);
            if (count($packages) === 0) {
                return "\n    ".implode(self::getMissingPackageReason($repositorySet, $request, $pool, $isVerbose, $packageName, $constraint));
            }
        }

        return self::formatDeduplicatedRules($reasons, '    ', $repositorySet, $request, $pool, $isVerbose, $installedMap, $learnedPool);
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
                    if (count($versions) > 1) {
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

                $version = self::getPlatformPackageVersion($pool, $packageName, phpversion($ext) ?: '0');
                if (null === $version) {
                    if (extension_loaded($ext)) {
                        return [
                            $msg,
                            'the '.$packageName.' package is disabled by your platform config. Enable it again with "composer config platform.'.$packageName.' --unset".',
                        ];
                    }

                    return [$msg, 'it is missing from your system. Install or enable PHP\'s '.$ext.' extension.'];
                }

                return [$msg, 'it has the wrong version installed ('.$version.').'];
            }

            // handle linked libs
            if (0 === stripos($packageName, 'lib-')) {
                if (strtolower($packageName) === 'lib-icu') {
                    $error = extension_loaded('intl') ? 'it has the wrong version installed, try upgrading the intl extension.' : 'it is missing from your system, make sure the intl extension is loaded.';

                    return ["- Root composer.json requires linked library ".$packageName.self::constraintToText($constraint).' but ', $error];
                }

                return ["- Root composer.json requires linked library ".$packageName.self::constraintToText($constraint).' but ', 'it has the wrong version installed or is missing from your system, make sure to load the extension providing it.'];
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
                new Constraint(Constraint::STR_OP_EQ, str_replace('#', '+', $newConstraint))
            ], false));
            if (\count($packages) > 0) {
                return ["- Root composer.json requires $packageName".self::constraintToText($constraint) . ', ', 'found '.self::getPackageList($packages, $isVerbose, $pool, $constraint).'. The # character in branch names is replaced by a + character. Make sure to require it as "'.str_replace('#', '+', $constraint->getPrettyString()).'".'];
            }
        }

        // first check if the actual requested package is found in normal conditions
        // if so it must mean it is rejected by another constraint than the one given here
        if ($packages = $repositorySet->findPackages($packageName, $constraint)) {
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
            if (isset($tempReqs[$packageName])) {
                $filtered = array_filter($packages, static function ($p) use ($tempReqs, $packageName): bool {
                    return $tempReqs[$packageName]->matches(new Constraint('==', $p->getVersion()));
                });
                if (0 === count($filtered)) {
                    return ["- Root composer.json requires $packageName".self::constraintToText($constraint) . ', ', 'found '.self::getPackageList($packages, $isVerbose, $pool, $constraint).' but '.(self::hasMultipleNames($packages) ? 'these conflict' : 'it conflicts').' with your temporary update constraint ('.$packageName.':'.$tempReqs[$packageName]->getPrettyString().').'];
                }
            }

            if ($lockedPackage) {
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

            if (!$nonLockedPackages) {
                return ["- Root composer.json requires $packageName".self::constraintToText($constraint) . ', ', 'found '.self::getPackageList($packages, $isVerbose, $pool, $constraint).' in the lock file but not in remote repositories, make sure you avoid updating this package to keep the one from the lock file.'];
            }

            return ["- Root composer.json requires $packageName".self::constraintToText($constraint) . ', ', 'found '.self::getPackageList($packages, $isVerbose, $pool, $constraint).' but these were not loaded, likely because '.(self::hasMultipleNames($packages) ? 'they conflict' : 'it conflicts').' with another require.'];
        }

        // check if the package is found when bypassing stability checks
        if ($packages = $repositorySet->findPackages($packageName, $constraint, RepositorySet::ALLOW_UNACCEPTABLE_STABILITIES)) {
            // we must first verify if a valid package would be found in a lower priority repository
            if ($allReposPackages = $repositorySet->findPackages($packageName, $constraint, RepositorySet::ALLOW_SHADOWED_REPOSITORIES)) {
                return self::computeCheckForLowerPrioRepo($pool, $isVerbose, $packageName, $packages, $allReposPackages, 'minimum-stability', $constraint);
            }

            return ["- Root composer.json requires $packageName".self::constraintToText($constraint) . ', ', 'found '.self::getPackageList($packages, $isVerbose, $pool, $constraint).' but '.(self::hasMultipleNames($packages) ? 'these do' : 'it does').' not match your minimum-stability.'];
        }

        // check if the package is found when bypassing the constraint and stability checks
        if ($packages = $repositorySet->findPackages($packageName, null, RepositorySet::ALLOW_UNACCEPTABLE_STABILITIES)) {
            // we must first verify if a valid package would be found in a lower priority repository
            if ($allReposPackages = $repositorySet->findPackages($packageName, $constraint, RepositorySet::ALLOW_SHADOWED_REPOSITORIES)) {
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

        if ($providers = $repositorySet->getProviders($packageName)) {
            $maxProviders = 20;
            $providersStr = implode(array_map(static function ($p): string {
                $description = $p['description'] ? ' '.substr($p['description'], 0, 100) : '';

                return '      - '.$p['name'].$description."\n";
            }, count($providers) > $maxProviders + 1 ? array_slice($providers, 0, $maxProviders) : $providers));
            if (count($providers) > $maxProviders + 1) {
                $providersStr .= '      ... and '.(count($providers) - $maxProviders).' more.'."\n";
            }

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
            if ($pool && $constraint) {
                foreach ($pool->getRemovedVersions($package->getName(), $constraint) as $version => $prettyVersion) {
                    $prepared[$package->getName()]['versions'][$version] = $prettyVersion;
                }
            }
            if ($pool && $useRemovedVersionGroup) {
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

        if (count($available)) {
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
                $version .= '; ' . str_replace('Package ', '', $selected->getDescription());
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
     * @param PackageInterface[] $higherRepoPackages
     * @param PackageInterface[] $allReposPackages
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

        if ($higherRepoPackages) {
            $topPackage = reset($higherRepoPackages);
            if ($topPackage instanceof RootPackageInterface) {
                return [
                    "- Root composer.json requires $packageName".self::constraintToText($constraint).', it is ',
                    'satisfiable by '.self::getPackageList($nextRepoPackages, $isVerbose, $pool, $constraint).' from '.$nextRepo->getRepoName().' but '.$topPackage->getPrettyName().' is the root package and cannot be modified. See https://getcomposer.org/dep-on-root for details and assistance.',
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

        return $constraint ? ' '.$constraint->getPrettyString() : '';
    }
}
