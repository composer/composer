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
}
