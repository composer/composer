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

use Composer\Package\LinkConstraint\LinkConstraintInterface;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class Request
{
    protected $jobs;
    protected $pool;

    public function __construct(Pool $pool)
    {
        $this->pool = $pool;
        $this->jobs = array();
    }

    public function install($packageName, LinkConstraintInterface $constraint = null)
    {
        $this->addJob($packageName, 'install', $constraint);
    }

    public function update($packageName, LinkConstraintInterface $constraint = null)
    {
        $this->addJob($packageName, 'update', $constraint);
    }

    public function remove($packageName, LinkConstraintInterface $constraint = null)
    {
        $this->addJob($packageName, 'remove', $constraint);
    }

    /**
     * Mark an existing package as being installed and having to remain installed
     *
     * These jobs will not be tempered with by the solver
     */
    public function fix($packageName, LinkConstraintInterface $constraint = null)
    {
        $this->addJob($packageName, 'install', $constraint, true);
    }

    protected function addJob($packageName, $cmd, LinkConstraintInterface $constraint = null, $fixed = false)
    {
        $packageName = strtolower($packageName);

        $this->jobs[] = array(
            'cmd' => $cmd,
            'packageName' => $packageName,
            'constraint' => $constraint,
            'fixed' => $fixed
        );
    }

    public function updateAll()
    {
        $this->jobs[] = array('cmd' => 'update-all');
    }

    public function getJobs()
    {
        return $this->jobs;
    }
}
