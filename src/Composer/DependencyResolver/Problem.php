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

use Composer\Package\CompletePackageInterface;
use Composer\Package\AliasPackage;
use Composer\Package\RootPackageInterface;
use Composer\Repository\RepositorySet;
use Composer\Repository\LockArrayRepository;
use Composer\Semver\Constraint\Constraint;
use Composer\Package\Version\VersionParser;

/**
 * Represents a problem detected while solving dependencies
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class Problem
{
    /**
     * A map containing the id of each rule part of this problem as a key
     * @var array
     */
    protected $reasonSeen;

    /**
     * A set of reasons for the problem, each is a rule or a root require and a rule
     * @var array
     */
    protected $reasons = array();

    protected $section = 0;

    /**
     * Add a rule as a reason
     *
     * @param Rule $rule A rule which is a reason for this problem
     */
    public function addRule(Rule $rule)
    {
        $this->addReason(spl_object_hash($rule), $rule);
    }

    /**
     * Retrieve all reasons for this problem
     *
     * @return array The problem's reasons
     */
    public function getReasons()
    {
        return $this->reasons;
    }

    /**
     * A human readable textual representation of the problem's reasons
     *
     * @param  array  $installedMap A map of all present packages
     * @return string
     */
    public function getPrettyString(RepositorySet $repositorySet, Request $request, Pool $pool, $isVerbose, array $installedMap = array(), array $learnedPool = array())
    {
        // TODO doesn't this entirely defeat the purpose of the problem sections? what's the point of sections?
        $reasons = call_user_func_array('array_merge', array_reverse($this->reasons));

        if (count($reasons) === 1) {
            reset($reasons);
            $rule = current($reasons);

            if (!in_array($rule->getReason(), array(Rule::RULE_ROOT_REQUIRE, Rule::RULE_FIXED), true)) {
                throw new \LogicException("Single reason problems must contain a request rule.");
            }

            $reasonData = $rule->getReasonData();
            $packageName = $reasonData['packageName'];
            $constraint = $reasonData['constraint'];

            if (isset($constraint)) {
                $packages = $pool->whatProvides($packageName, $constraint);
            } else {
                $packages = array();
            }

            if (empty($packages)) {
                return "\n    ".implode(self::getMissingPackageReason($repositorySet, $request, $pool, $isVerbose, $packageName, $constraint));
            }
        }

        return self::formatDeduplicatedRules($reasons, '    ', $repositorySet, $request, $pool, $isVerbose, $installedMap, $learnedPool);
    }

    /**
     * @internal
     */
    public static function formatDeduplicatedRules($rules, $indent, RepositorySet $repositorySet, Request $request, Pool $pool, $isVerbose, array $installedMap = array(), array $learnedPool = array())
    {
        $messages = array();
        $templates = array();
        $parser = new VersionParser;
        $deduplicatableRuleTypes = array(Rule::RULE_PACKAGE_REQUIRES, Rule::RULE_PACKAGE_CONFLICT);
        foreach ($rules as $rule) {
            $message = $rule->getPrettyString($repositorySet, $request, $pool, $isVerbose, $installedMap, $learnedPool);
            if (in_array($rule->getReason(), $deduplicatableRuleTypes, true) && preg_match('{^(?P<package>\S+) (?P<version>\S+) (?P<type>requires|conflicts)}', $message, $m)) {
                $template = preg_replace('{^\S+ \S+ }', '%s%s ', $message);
                $messages[] = $template;
                $templates[$template][$m[1]][$parser->normalize($m[2])] = $m[2];
            } elseif ($message !== '') {
                $messages[] = $message;
            }
        }

        $result = array();
        foreach (array_unique($messages) as $message) {
            if (isset($templates[$message])) {
                foreach ($templates[$message] as $package => $versions) {
                    uksort($versions, 'version_compare');
                    if (!$isVerbose) {
                        $versions = self::condenseVersionList($versions, 1);
                    }
                    if (count($versions) > 1) {
                        // remove the s from requires/conflicts to correct grammar
                        $message = preg_replace('{^(%s%s (?:require|conflict))s}', '$1', $message);
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

    public function isCausedByLock(RepositorySet $repositorySet, Request $request, Pool $pool)
    {
        foreach ($this->reasons as $sectionRules) {
            foreach ($sectionRules as $rule) {
                if ($rule->isCausedByLock($repositorySet, $request, $pool)) {
                    return true;
                }
            }
        }
    }

    /**
     * Store a reason descriptor but ignore duplicates
     *
     * @param string $id     A canonical identifier for the reason
     * @param Rule   $reason The reason descriptor
     */
    protected function addReason($id, Rule $reason)
    {
        // TODO: if a rule is part of a problem description in two sections, isn't this going to remove a message
        // that is important to understand the issue?

        if (!isset($this->reasonSeen[$id])) {
            $this->reasonSeen[$id] = true;
            $this->reasons[$this->section][] = $reason;
        }
    }

    public function nextSection()
    {
        $this->section++;
    }

    /**
     * @internal
     */
    public static function getMissingPackageReason(RepositorySet $repositorySet, Request $request, Pool $pool, $isVerbose, $packageName, $constraint = null)
    {
        // handle php/hhvm
        if ($packageName === 'php' || $packageName === 'php-64bit' || $packageName === 'hhvm') {
            $version = self::getPlatformPackageVersion($pool, $packageName, phpversion());

            $msg = "- Root composer.json requires ".$packageName.self::constraintToText($constraint).' but ';

            if (defined('HHVM_VERSION') || ($packageName === 'hhvm' && count($pool->whatProvides($packageName)) > 0)) {
                return array($msg, 'your HHVM version does not satisfy that requirement.');
            }

            if ($packageName === 'hhvm') {
                return array($msg, 'HHVM was not detected on this machine, make sure it is in your PATH.');
            }

            return array($msg, 'your '.$packageName.' version ('. $version .') does not satisfy that requirement.');
        }

        // handle php extensions
        if (0 === stripos($packageName, 'ext-')) {
            if (false !== strpos($packageName, ' ')) {
                return array('- ', "PHP extension ".$packageName.' should be required as '.str_replace(' ', '-', $packageName).'.');
            }

            $ext = substr($packageName, 4);
            $version = self::getPlatformPackageVersion($pool, $packageName, phpversion($ext) ?: '0');

            $error = extension_loaded($ext) ? 'it has the wrong version ('.$version.') installed' : 'it is missing from your system';

            return array("- Root composer.json requires PHP extension ".$packageName.self::constraintToText($constraint).' but ', $error.'. Install or enable PHP\'s '.$ext.' extension.');
        }

        // handle linked libs
        if (0 === stripos($packageName, 'lib-')) {
            if (strtolower($packageName) === 'lib-icu') {
                $error = extension_loaded('intl') ? 'it has the wrong version installed, try upgrading the intl extension.' : 'it is missing from your system, make sure the intl extension is loaded.';

                return array("- Root composer.json requires linked library ".$packageName.self::constraintToText($constraint).' but ', $error);
            }

            return array("- Root composer.json requires linked library ".$packageName.self::constraintToText($constraint).' but ', 'it has the wrong version installed or is missing from your system, make sure to load the extension providing it.');
        }

        $lockedPackage = null;
        foreach ($request->getLockedPackages() as $package) {
            if ($package->getName() === $packageName) {
                $lockedPackage = $package;
                if ($pool->isUnacceptableFixedOrLockedPackage($package)) {
                    return array("- ", $package->getPrettyName().' is fixed to '.$package->getPrettyVersion().' (lock file version) by a partial update but that version is rejected by your minimum-stability. Make sure you list it as an argument for the update command.');
                }
                break;
            }
        }

        // first check if the actual requested package is found in normal conditions
        // if so it must mean it is rejected by another constraint than the one given here
        if ($packages = $repositorySet->findPackages($packageName, $constraint)) {
            $rootReqs = $repositorySet->getRootRequires();
            if (isset($rootReqs[$packageName])) {
                $filtered = array_filter($packages, function ($p) use ($rootReqs, $packageName) {
                    return $rootReqs[$packageName]->matches(new Constraint('==', $p->getVersion()));
                });
                if (0 === count($filtered)) {
                    return array("- Root composer.json requires $packageName".self::constraintToText($constraint) . ', ', 'found '.self::getPackageList($packages, $isVerbose).' but '.(self::hasMultipleNames($packages) ? 'these conflict' : 'it conflicts').' with your root composer.json require ('.$rootReqs[$packageName]->getPrettyString().').');
                }
            }

            if ($lockedPackage) {
                $fixedConstraint = new Constraint('==', $lockedPackage->getVersion());
                $filtered = array_filter($packages, function ($p) use ($fixedConstraint) {
                    return $fixedConstraint->matches(new Constraint('==', $p->getVersion()));
                });
                if (0 === count($filtered)) {
                    return array("- Root composer.json requires $packageName".self::constraintToText($constraint) . ', ', 'found '.self::getPackageList($packages, $isVerbose).' but the package is fixed to '.$lockedPackage->getPrettyVersion().' (lock file version) by a partial update and that version does not match. Make sure you list it as an argument for the update command.');
                }
            }

            $nonLockedPackages = array_filter($packages, function ($p) {
                return !$p->getRepository() instanceof LockArrayRepository;
            });

            if (!$nonLockedPackages) {
                return array("- Root composer.json requires $packageName".self::constraintToText($constraint) . ', ', 'found '.self::getPackageList($packages, $isVerbose).' in the lock file but not in remote repositories, make sure you avoid updating this package to keep the one from the lock file.');
            }

            return array("- Root composer.json requires $packageName".self::constraintToText($constraint) . ', ', 'found '.self::getPackageList($packages, $isVerbose).' but these were not loaded, likely because '.(self::hasMultipleNames($packages) ? 'they conflict' : 'it conflicts').' with another require.');
        }

        // check if the package is found when bypassing stability checks
        if ($packages = $repositorySet->findPackages($packageName, $constraint, RepositorySet::ALLOW_UNACCEPTABLE_STABILITIES)) {
            // we must first verify if a valid package would be found in a lower priority repository
            if ($allReposPackages = $repositorySet->findPackages($packageName, $constraint, RepositorySet::ALLOW_SHADOWED_REPOSITORIES)) {
                return self::computeCheckForLowerPrioRepo($isVerbose, $packageName, $constraint, $packages, $allReposPackages, 'minimum-stability');
            }

            return array("- Root composer.json requires $packageName".self::constraintToText($constraint) . ', ', 'found '.self::getPackageList($packages, $isVerbose).' but '.(self::hasMultipleNames($packages) ? 'these do' : 'it does').' not match your minimum-stability.');
        }

        // check if the package is found when bypassing the constraint and stability checks
        if ($packages = $repositorySet->findPackages($packageName, null, RepositorySet::ALLOW_UNACCEPTABLE_STABILITIES)) {
            // we must first verify if a valid package would be found in a lower priority repository
            if ($allReposPackages = $repositorySet->findPackages($packageName, $constraint, RepositorySet::ALLOW_SHADOWED_REPOSITORIES)) {
                return self::computeCheckForLowerPrioRepo($isVerbose, $packageName, $constraint, $packages, $allReposPackages, 'constraint');
            }

            $suffix = '';
            if ($constraint instanceof Constraint && $constraint->getVersion() === 'dev-master') {
                foreach ($packages as $candidate) {
                    if (in_array($candidate->getVersion(), array('dev-default', 'dev-main'), true)) {
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

            return array("- Root composer.json requires $packageName".self::constraintToText($constraint) . ', ', 'found '.self::getPackageList($packages, $isVerbose).' but '.(self::hasMultipleNames($packages) ? 'these do' : 'it does').' not match the constraint.' . $suffix);
        }

        if (!preg_match('{^[A-Za-z0-9_./-]+$}', $packageName)) {
            $illegalChars = preg_replace('{[A-Za-z0-9_./-]+}', '', $packageName);

            return array("- Root composer.json requires $packageName, it ", 'could not be found, it looks like its name is invalid, "'.$illegalChars.'" is not allowed in package names.');
        }

        if ($providers = $repositorySet->getProviders($packageName)) {
            $maxProviders = 20;
            $providersStr = implode(array_map(function ($p) {
                $description = $p['description'] ? ' '.substr($p['description'], 0, 100) : '';

                return "      - ${p['name']}".$description."\n";
            }, count($providers) > $maxProviders + 1 ? array_slice($providers, 0, $maxProviders) : $providers));
            if (count($providers) > $maxProviders + 1) {
                $providersStr .= '      ... and '.(count($providers) - $maxProviders).' more.'."\n";
            }

            return array("- Root composer.json requires $packageName".self::constraintToText($constraint).", it ", "could not be found in any version, but the following packages provide it:\n".$providersStr."      Consider requiring one of these to satisfy the $packageName requirement.");
        }

        return array("- Root composer.json requires $packageName, it ", "could not be found in any version, there may be a typo in the package name.");
    }

    /**
     * @internal
     */
    public static function getPackageList(array $packages, $isVerbose)
    {
        $prepared = array();
        $hasDefaultBranch = array();
        foreach ($packages as $package) {
            $prepared[$package->getName()]['name'] = $package->getPrettyName();
            $prepared[$package->getName()]['versions'][$package->getVersion()] = $package->getPrettyVersion().($package instanceof AliasPackage ? ' (alias of '.$package->getAliasOf()->getPrettyVersion().')' : '');
            if ($package->isDefaultBranch()) {
                $hasDefaultBranch[$package->getName()] = true;
            }
        }
        foreach ($prepared as $name => $package) {
            // remove the implicit default branch alias to avoid cruft in the display
            if (isset($package['versions'][VersionParser::DEFAULT_BRANCH_ALIAS], $hasDefaultBranch[$name])) {
                unset($package['versions'][VersionParser::DEFAULT_BRANCH_ALIAS]);
            }

            uksort($package['versions'], 'version_compare');

            if (!$isVerbose) {
                $package['versions'] = self::condenseVersionList($package['versions'], 4);
            }
            $prepared[$name] = $package['name'].'['.implode(', ', $package['versions']).']';
        }

        return implode(', ', $prepared);
    }

    private static function getPlatformPackageVersion(Pool $pool, $packageName, $version)
    {
        $available = $pool->whatProvides($packageName);

        if (count($available)) {
            $firstAvailable = reset($available);
            $version = $firstAvailable->getPrettyVersion();
            $extra = $firstAvailable->getExtra();
            if ($firstAvailable instanceof CompletePackageInterface && isset($extra['config.platform']) && $extra['config.platform'] === true) {
                $version .= '; ' . str_replace('Package ', '', $firstAvailable->getDescription());
            }
        }

        return $version;
    }

    /**
     * @param  string[]     $versions an array of pretty versions, with normalized versions as keys
     * @return list<string> a list of pretty versions and '...' where versions were removed
     */
    private static function condenseVersionList(array $versions, $max, $maxDev = 16)
    {
        if (count($versions) <= $max) {
            return $versions;
        }

        $filtered = array();
        $byMajor = array();
        foreach ($versions as $version => $pretty) {
            if (0 === stripos($version, 'dev-')) {
                $byMajor['dev'][] = $pretty;
            } else {
                $byMajor[preg_replace('{^(\d+)\..*}', '$1', $version)][] = $pretty;
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

    private static function hasMultipleNames(array $packages)
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

    private static function computeCheckForLowerPrioRepo($isVerbose, $packageName, $constraint, array $higherRepoPackages, array $allReposPackages, $reason)
    {
        $nextRepoPackages = array();
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
                return array(
                    "- Root composer.json requires $packageName".self::constraintToText($constraint).', it is ',
                    'satisfiable by '.self::getPackageList($nextRepoPackages, $isVerbose).' from '.$nextRepo->getRepoName().' but '.$topPackage->getPrettyName().' is the root package and cannot be modified. See https://getcomposer.org/dep-on-root for details and assistance.',
                );
            }
        }

        if ($nextRepo instanceof LockArrayRepository) {
            $singular = count($higherRepoPackages) === 1;

            return array("- Root composer.json requires $packageName".self::constraintToText($constraint) . ', it is ',
                'found '.self::getPackageList($nextRepoPackages, $isVerbose).' in the lock file and '.self::getPackageList($higherRepoPackages, $isVerbose).' from '.reset($higherRepoPackages)->getRepository()->getRepoName().' but ' . ($singular ? 'it does' : 'these do') . ' not match your '.$reason.' and ' . ($singular ? 'is' : 'are') . ' therefore not installable. Make sure you either fix the '.$reason.' or avoid updating this package to keep the one from the lock file.', );
        }

        return array("- Root composer.json requires $packageName".self::constraintToText($constraint) . ', it is ', 'satisfiable by '.self::getPackageList($nextRepoPackages, $isVerbose).' from '.$nextRepo->getRepoName().' but '.self::getPackageList($higherRepoPackages, $isVerbose).' from '.reset($higherRepoPackages)->getRepository()->getRepoName().' has higher repository priority. The packages with higher priority do not match your '.$reason.' and are therefore not installable. See https://getcomposer.org/repoprio for details and assistance.');
    }

    /**
     * Turns a constraint into text usable in a sentence describing a request
     *
     * @param  \Composer\Semver\Constraint\ConstraintInterface $constraint
     * @return string
     */
    protected static function constraintToText($constraint)
    {
        return $constraint ? ' '.$constraint->getPrettyString() : '';
    }
}
