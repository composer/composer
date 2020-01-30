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
use Composer\Repository\RepositorySet;
use Composer\Semver\Constraint\Constraint;

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
    public function getPrettyString(RepositorySet $repositorySet, Request $request, array $installedMap = array(), array $learnedPool = array())
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
                $packages = $repositorySet->getPool()->whatProvides($packageName, $constraint);
            } else {
                $packages = array();
            }

            if (empty($packages)) {
                return "\n    ".implode(self::getMissingPackageReason($repositorySet, $request, $packageName, $constraint));
            }
        }

        $messages = array();

        foreach ($reasons as $rule) {
            $messages[] = $rule->getPrettyString($repositorySet, $request, $installedMap, $learnedPool);
        }

        return "\n    - ".implode("\n    - ", $messages);
    }

    /**
     * Store a reason descriptor but ignore duplicates
     *
     * @param string $id     A canonical identifier for the reason
     * @param Rule $reason The reason descriptor
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
    public static function getMissingPackageReason(RepositorySet $repositorySet, Request $request, $packageName, $constraint = null)
    {
        $pool = $repositorySet->getPool();

        // handle php/hhvm
        if ($packageName === 'php' || $packageName === 'php-64bit' || $packageName === 'hhvm') {
            $version = phpversion();
            $available = $pool->whatProvides($packageName);

            if (count($available)) {
                $firstAvailable = reset($available);
                $version = $firstAvailable->getPrettyVersion();
                $extra = $firstAvailable->getExtra();
                if ($firstAvailable instanceof CompletePackageInterface && isset($extra['config.platform']) && $extra['config.platform'] === true) {
                    $version .= '; ' . str_replace('Package ', '', $firstAvailable->getDescription());
                }
            }

            $msg = "- Root composer.json requires ".$packageName.self::constraintToText($constraint).' but ';

            if (defined('HHVM_VERSION') || (count($available) && $packageName === 'hhvm')) {
                return array($msg, 'your HHVM version does not satisfy that requirement.');
            }

            if ($packageName === 'hhvm') {
                return array($msg, 'you are running this with PHP and not HHVM.');
            }

            return array($msg, 'your '.$packageName.' version ('. $version .') does not satisfy that requirement.');
        }

        // handle php extensions
        if (0 === stripos($packageName, 'ext-')) {
            if (false !== strpos($packageName, ' ')) {
                return array('- ', "PHP extension ".$packageName.' should be required as '.str_replace(' ', '-', $packageName).'.');
            }

            $ext = substr($packageName, 4);
            $error = extension_loaded($ext) ? 'it has the wrong version ('.(phpversion($ext) ?: '0').') installed' : 'it is missing from your system';

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

        $fixedPackage = null;
        foreach ($request->getFixedPackages() as $package) {
            if ($package->getName() === $packageName) {
                $fixedPackage = $package;
                if ($pool->isUnacceptableFixedPackage($package)) {
                    return array("- ", $package->getPrettyName().' is fixed to '.$package->getPrettyVersion().' (lock file version) by a partial update but that version is rejected by your minimum-stability. Make sure you whitelist it for update.');
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
                    return array("- Root composer.json requires $packageName".self::constraintToText($constraint) . ', ', 'found '.self::getPackageList($packages).' but '.(self::hasMultipleNames($packages) ? 'these conflict' : 'it conflicts').' with your root composer.json require ('.$rootReqs[$packageName]->getPrettyString().').');
                }
            }

            if ($fixedPackage) {
                $fixedConstraint = new Constraint('==', $fixedPackage->getVersion());
                $filtered = array_filter($packages, function ($p) use ($fixedConstraint) {
                    return $fixedConstraint->matches(new Constraint('==', $p->getVersion()));
                });
                if (0 === count($filtered)) {
                    return array("- Root composer.json requires $packageName".self::constraintToText($constraint) . ', ', 'found '.self::getPackageList($packages).' but the package is fixed to '.$fixedPackage->getPrettyVersion().' (lock file version) by a partial update and that version does not match. Make sure you whitelist it for update.');
                }
            }

            return array("- Root composer.json requires $packageName".self::constraintToText($constraint) . ', ', 'found '.self::getPackageList($packages).' but '.(self::hasMultipleNames($packages) ? 'these conflict' : 'it conflicts').' with another require.');
        }

        // check if the package is found when bypassing stability checks
        if ($packages = $repositorySet->findPackages($packageName, $constraint, RepositorySet::ALLOW_UNACCEPTABLE_STABILITIES)) {
            return array("- Root composer.json requires $packageName".self::constraintToText($constraint) . ', ', 'found '.self::getPackageList($packages).' but '.(self::hasMultipleNames($packages) ? 'these do' : 'it does').' not match your minimum-stability.');
        }

        // check if the package is found when bypassing the constraint check
        if ($packages = $repositorySet->findPackages($packageName, null)) {
            // we must first verify if a valid package would be found in a lower priority repository
            if ($allReposPackages = $repositorySet->findPackages($packageName, $constraint, RepositorySet::ALLOW_SHADOWED_REPOSITORIES)) {
                $higherRepoPackages = $repositorySet->findPackages($packageName, null);
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

                return array("- Root composer.json requires $packageName".self::constraintToText($constraint) . ', it is ', 'satisfiable by '.self::getPackageList($nextRepoPackages).' from '.$nextRepo->getRepoName().' but '.self::getPackageList($higherRepoPackages).' from '.reset($higherRepoPackages)->getRepository()->getRepoName().' has higher repository priority. The packages with higher priority do not match your constraint and are therefore not installable.');
            }

            return array("- Root composer.json requires $packageName".self::constraintToText($constraint) . ', ', 'found '.self::getPackageList($packages).' but '.(self::hasMultipleNames($packages) ? 'these do' : 'it does').' not match your constraint.');
        }

        if (!preg_match('{^[A-Za-z0-9_./-]+$}', $packageName)) {
            $illegalChars = preg_replace('{[A-Za-z0-9_./-]+}', '', $packageName);

            return array("- Root composer.json requires $packageName, it ", 'could not be found, it looks like its name is invalid, "'.$illegalChars.'" is not allowed in package names.');
        }

        if ($providers = $repositorySet->getProviders($packageName)) {
            $maxProviders = 20;
            $providersStr = implode(array_map(function ($p) {
                return "      - ${p['name']} ".substr($p['description'], 0, 100)."\n";
            }, count($providers) > $maxProviders+1 ? array_slice($providers, 0, $maxProviders) : $providers));
            if (count($providers) > $maxProviders+1) {
                $providersStr .= '      ... and '.(count($providers)-$maxProviders).' more.'."\n";
            }
            return array("- Root composer.json requires $packageName".self::constraintToText($constraint).", it ", "could not be found in any version, but the following packages provide it: \n".$providersStr."      Consider requiring one of these to satisfy the $packageName requirement.");
        }

        return array("- Root composer.json requires $packageName, it ", "could not be found in any version, there may be a typo in the package name.");
    }

    /**
     * @internal
     */
    public static function getPackageList(array $packages)
    {
        $prepared = array();
        foreach ($packages as $package) {
            $prepared[$package->getName()]['name'] = $package->getPrettyName();
            $prepared[$package->getName()]['versions'][$package->getVersion()] = $package->getPrettyVersion();
        }
        foreach ($prepared as $name => $package) {
            $prepared[$name] = $package['name'].'['.implode(', ', $package['versions']).']';
        }

        return implode(', ', $prepared);
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
