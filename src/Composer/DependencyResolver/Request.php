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

    protected function addJob($packageName, $cmd, LinkConstraintInterface $constraint = null)
    {
        $packageName = strtolower($packageName);
        $packages = $this->pool->whatProvides($packageName, $constraint);

        $this->jobs[] = array(
            'packages' => $packages,
            'cmd' => $cmd,
            'packageName' => $packageName,
            'constraint' => $constraint,
        );
    }

    public function updateAll()
    {
        $this->jobs[] = array('cmd' => 'update-all', 'packages' => array());
    }

    public function getJobs()
    {
        return $this->jobs;
    }
}
