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
     * A set of reasons for the problem, each is a rule or a job and a rule
     * @var array
     */
    protected $reasons;

    /**
     * Add a job as a reason
     *
     * @param   array   $job    A job descriptor which is a reason for this problem
     * @param   Rule    $rule   An optional rule associated with the job
     */
    public function addJobRule($job, Rule $rule = null)
    {
        $this->addReason(serialize($job), array(
            'rule' => $rule,
            'job' => $job,
        ));
    }

    /**
     * Add a rule as a reason
     *
     * @param   Rule    $rule   A rule which is a reason for this problem
     */
    public function addRule(Rule $rule)
    {
        $this->addReason($rule->getId(), array(
            'rule' => $rule,
            'job' => null,
        ));
    }

    /**
     * Retrieve all reasons for this problem
     *
     * @return  array   The problem's reasons
     */
    public function getReasons()
    {
        return $this->reasons;
    }

    /**
     * A human readable textual representation of the problem's reasons
     */
    public function __toString()
    {
        if (count($this->reasons) === 1) {
            reset($this->reasons);
            $reason = current($this->reasons);

            $rule = $reason['rule'];
            $job = $reason['job'];

            if ($job && $job['cmd'] === 'install' && empty($job['packages'])) {
                // handle php extensions
                if (0 === stripos($job['packageName'], 'ext-')) {
                    $ext = substr($job['packageName'], 4);
                    $error = extension_loaded($ext) ? 'has the wrong version ('.phpversion($ext).') installed' : 'is missing from your system';
                    return 'The requested PHP extension "'.$job['packageName'].'" '.$this->constraintToText($job['constraint']).$error.'.';
                }
                return 'The requested package "'.$job['packageName'].'" '.$this->constraintToText($job['constraint']).'could not be found.';
            }
        }

        $messages = array("Problem caused by:");

        foreach ($this->reasons as $reason) {

            $rule = $reason['rule'];
            $job = $reason['job'];

            if ($job) {
                $messages[] = $this->jobToText($job);
            } elseif ($rule) {
                if ($rule instanceof Rule) {
                    $messages[] = $rule->toHumanReadableString();
                }
            }
        }

        return implode("\n\t\t\t- ", $messages);
    }

    /**
     * Store a reason descriptor but ignore duplicates
     *
     * @param   string  $id         A canonical identifier for the reason
     * @param   string  $reason     The reason descriptor
     */
    protected function addReason($id, $reason)
    {
        if (!isset($this->reasons[$id])) {
            $this->reasons[$id] = $reason;
        }
    }

    /**
     * Turns a job into a human readable description
     *
     * @param   array   $job
     * @return  string
     */
    protected function jobToText($job)
    {
        switch ($job['cmd']) {
            case 'install':
                return 'Installation of package "'.$job['packageName'].'" '.$this->constraintToText($job['constraint']).'was requested. Satisfiable by packages ['.implode(', ', $job['packages']).'].';
            case 'update':
                return 'Update of package "'.$job['packageName'].'" '.$this->constraintToText($job['constraint']).'was requested.';
            case 'remove':
                return 'Removal of package "'.$job['packageName'].'" '.$this->constraintToText($job['constraint']).'was requested.';
        }

        return 'Job(cmd='.$job['cmd'].', target='.$job['packageName'].', packages=['.implode(', ', $job['packages']).'])';
    }

    /**
     * Turns a constraint into text usable in a sentence describing a job
     *
     * @param   LinkConstraint  $constraint
     * @return  string
     */
    protected function constraintToText($constraint)
    {
        return ($constraint) ? 'with constraint '.$constraint.' ' : '';
    }
}
