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
     * A set of reasons for the problem, each is a rule or a job and a rule
     * @var array
     */
    protected $reasons = array();

    protected $section = 0;

    protected $pool;

    public function __construct(Pool $pool)
    {
        $this->pool = $pool;
    }

    /**
     * Add a rule as a reason
     *
     * @param Rule $rule A rule which is a reason for this problem
     */
    public function addRule(Rule $rule)
    {
        $this->addReason(spl_object_hash($rule), array(
            'rule' => $rule,
            'job' => $rule->getJob(),
        ));
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
     * @param  array  $installedMap A map of all installed packages
     * @return string
     */
    public function getPrettyString(array $installedMap = array())
    {
        $reasons = call_user_func_array('array_merge', array_reverse($this->reasons));

        if (count($reasons) === 1) {
            reset($reasons);
            $reason = current($reasons);

            $job = $reason['job'];

            $packageName = $job['packageName'];
            $constraint = $job['constraint'];

            if (isset($constraint)) {
                $packages = $this->pool->whatProvides($packageName, $constraint);
            } else {
                $packages = array();
            }

            if ($job && $job['cmd'] === 'install' && empty($packages)) {

                // handle php/hhvm
                if ($packageName === 'php' || $packageName === 'php-64bit' || $packageName === 'hhvm') {
                    $version = phpversion();
                    $available = $this->pool->whatProvides($packageName);

                    if (count($available)) {
                        $firstAvailable = reset($available);
                        $version = $firstAvailable->getPrettyVersion();
                        $extra = $firstAvailable->getExtra();
                        if ($firstAvailable instanceof CompletePackageInterface && isset($extra['config.platform']) && $extra['config.platform'] === true) {
                            $version .= '; ' . $firstAvailable->getDescription();
                        }
                    }

                    $msg = "\n    - This package requires ".$packageName.$this->constraintToText($constraint).' but ';

                    if (defined('HHVM_VERSION') || (count($available) && $packageName === 'hhvm')) {
                        return $msg . 'your HHVM version does not satisfy that requirement.';
                    }

                    if ($packageName === 'hhvm') {
                        return $msg . 'you are running this with PHP and not HHVM.';
                    }

                    return $msg . 'your PHP version ('. $version .') does not satisfy that requirement.';
                }

                // handle php extensions
                if (0 === stripos($packageName, 'ext-')) {
                    if (false !== strpos($packageName, ' ')) {
                        return "\n    - The requested PHP extension ".$packageName.' should be required as '.str_replace(' ', '-', $packageName).'.';
                    }

                    $ext = substr($packageName, 4);
                    $error = extension_loaded($ext) ? 'has the wrong version ('.(phpversion($ext) ?: '0').') installed' : 'is missing from your system';

                    return "\n    - The requested PHP extension ".$packageName.$this->constraintToText($constraint).' '.$error.'. Install or enable PHP\'s '.$ext.' extension.';
                }

                // handle linked libs
                if (0 === stripos($packageName, 'lib-')) {
                    if (strtolower($packageName) === 'lib-icu') {
                        $error = extension_loaded('intl') ? 'has the wrong version installed, try upgrading the intl extension.' : 'is missing from your system, make sure the intl extension is loaded.';

                        return "\n    - The requested linked library ".$packageName.$this->constraintToText($constraint).' '.$error;
                    }

                    return "\n    - The requested linked library ".$packageName.$this->constraintToText($constraint).' has the wrong version installed or is missing from your system, make sure to load the extension providing it.';
                }

                if (!preg_match('{^[A-Za-z0-9_./-]+$}', $packageName)) {
                    $illegalChars = preg_replace('{[A-Za-z0-9_./-]+}', '', $packageName);

                    return "\n    - The requested package ".$packageName.' could not be found, it looks like its name is invalid, "'.$illegalChars.'" is not allowed in package names.';
                }

                if ($providers = $this->pool->whatProvides($packageName, $constraint, true, true)) {
                    return "\n    - The requested package ".$packageName.$this->constraintToText($constraint).' is satisfiable by '.$this->getPackageList($providers).' but these conflict with your requirements or minimum-stability.';
                }

                if ($providers = $this->pool->whatProvides($packageName, null, true, true)) {
                    return "\n    - The requested package ".$packageName.$this->constraintToText($constraint).' exists as '.$this->getPackageList($providers).' but these are rejected by your constraint.';
                }

                return "\n    - The requested package ".$packageName.' could not be found in any version, there may be a typo in the package name.';
            }
        }

        $messages = array();

        foreach ($reasons as $reason) {
            $rule = $reason['rule'];
            $job = $reason['job'];

            if ($job) {
                $messages[] = $this->jobToText($job);
            } elseif ($rule) {
                if ($rule instanceof Rule) {
                    $messages[] = $rule->getPrettyString($this->pool, $installedMap);
                }
            }
        }

        return "\n    - ".implode("\n    - ", $messages);
    }

    /**
     * Store a reason descriptor but ignore duplicates
     *
     * @param string $id     A canonical identifier for the reason
     * @param string $reason The reason descriptor
     */
    protected function addReason($id, $reason)
    {
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
     * Turns a job into a human readable description
     *
     * @param  array  $job
     * @return string
     */
    protected function jobToText($job)
    {
        $packageName = $job['packageName'];
        $constraint = $job['constraint'];
        switch ($job['cmd']) {
            case 'install':
                $packages = $this->pool->whatProvides($packageName, $constraint);
                if (!$packages) {
                    return 'No package found to satisfy install request for '.$packageName.$this->constraintToText($constraint);
                }

                return 'Installation request for '.$packageName.$this->constraintToText($constraint).' -> satisfiable by '.$this->getPackageList($packages).'.';
            case 'update':
                return 'Update request for '.$packageName.$this->constraintToText($constraint).'.';
            case 'remove':
                return 'Removal request for '.$packageName.$this->constraintToText($constraint).'';
        }

        if (isset($constraint)) {
            $packages = $this->pool->whatProvides($packageName, $constraint);
        } else {
            $packages = array();
        }

        return 'Job(cmd='.$job['cmd'].', target='.$packageName.', packages=['.$this->getPackageList($packages).'])';
    }

    protected function getPackageList($packages)
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

    /**
     * Turns a constraint into text usable in a sentence describing a job
     *
     * @param  \Composer\Semver\Constraint\ConstraintInterface $constraint
     * @return string
     */
    protected function constraintToText($constraint)
    {
        return $constraint ? ' '.$constraint->getPrettyString() : '';
    }
}
