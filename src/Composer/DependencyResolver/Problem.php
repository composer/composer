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

    /**
     * Add a rule as a reason
     *
     * @param Rule $rule A rule which is a reason for this problem
     */
    public function addRule(Rule $rule)
    {
        $this->addReason($rule->getId(), array(
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
     * @param array $installedMap A map of all installed packages
     */
    public function getPrettyString(array $installedMap = array())
    {
        $reasons = call_user_func_array('array_merge', array_reverse($this->reasons));

        if (count($reasons) === 1) {
            reset($reasons);
            $reason = current($reasons);

            $rule = $reason['rule'];
            $job = $reason['job'];

            if ($job && $job['cmd'] === 'install' && empty($job['packages'])) {
                // handle php extensions
                if (0 === stripos($job['packageName'], 'ext-')) {
                    $ext = substr($job['packageName'], 4);
                    $error = extension_loaded($ext) ? 'has the wrong version ('.phpversion($ext).') installed' : 'is missing from your system';

                    return "\n    - The requested PHP extension ".$job['packageName'].$this->constraintToText($job['constraint']).' '.$error.'.';
                }

                // handle linked libs
                if (0 === stripos($job['packageName'], 'lib-')) {
                    $lib = substr($job['packageName'], 4);

                    return "\n    - The requested linked library ".$job['packageName'].$this->constraintToText($job['constraint']).' has the wrong version instaled or is missing from your system, make sure to have the extension providing it.';
                }

                return "\n    - The requested package ".$job['packageName'].$this->constraintToText($job['constraint']).' could not be found.';
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
                    $messages[] = $rule->getPrettyString($installedMap);
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
        switch ($job['cmd']) {
            case 'install':
                if (!$job['packages']) {
                    return 'No package found to satisfy install request for '.$job['packageName'].$this->constraintToText($job['constraint']);
                }

                return 'Installation request for '.$job['packageName'].$this->constraintToText($job['constraint']).' -> satisfiable by '.$this->getPackageList($job['packages']).'.';
            case 'update':
                return 'Update request for '.$job['packageName'].$this->constraintToText($job['constraint']).'.';
            case 'remove':
                return 'Removal request for '.$job['packageName'].$this->constraintToText($job['constraint']).'';
        }

        return 'Job(cmd='.$job['cmd'].', target='.$job['packageName'].', packages=['.$this->getPackageList($job['packages']).'])';
    }

    protected function getPackageList($packages)
    {
        return implode(', ', array_unique(array_map(function ($package) {
                return $package->getPrettyString();
            },
            $packages
        )));
    }

    /**
     * Turns a constraint into text usable in a sentence describing a job
     *
     * @param  LinkConstraint $constraint
     * @return string
     */
    protected function constraintToText($constraint)
    {
        return ($constraint) ? ' '.$constraint->getPrettyString() : '';
    }
}
