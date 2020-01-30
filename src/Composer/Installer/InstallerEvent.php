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

namespace Composer\Installer;

use Composer\Composer;
use Composer\DependencyResolver\PolicyInterface;
use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Transaction;
use Composer\EventDispatcher\Event;
use Composer\IO\IOInterface;
use Composer\Repository\RepositorySet;

/**
 * An event for all installer.
 *
 * @author François Pluchino <francois.pluchino@gmail.com>
 */
class InstallerEvent extends Event
{
    /**
     * @var Composer
     */
    private $composer;

    /**
     * @var IOInterface
     */
    private $io;

    /**
     * @var bool
     */
    private $devMode;

    /**
     * @var RepositorySet
     */
    private $repositorySet;

    /**
     * @var Pool
     */
    private $pool;

    /**
     * @var Request
     */
    private $request;

    /**
     * @var PolicyInterface
     */
    private $policy;

    /**
     * @var Transaction|null
     */
    private $transaction;

    /**
     * Constructor.
     *
     * @param string               $eventName
     * @param Composer             $composer
     * @param IOInterface          $io
     * @param bool                 $devMode
     * @param RepositorySet        $repositorySet
     * @param Pool                 $pool
     * @param Request              $request
     * @param PolicyInterface      $policy
     * @param Transaction          $transaction
     */
    public function __construct($eventName, Composer $composer, IOInterface $io, $devMode, RepositorySet $repositorySet, Pool $pool, Request $request, PolicyInterface $policy, Transaction $transaction = null)
    {
        parent::__construct($eventName);

        $this->composer = $composer;
        $this->io = $io;
        $this->devMode = $devMode;
        $this->repositorySet = $repositorySet;
        $this->pool = $pool;
        $this->request = $request;
        $this->policy = $policy;
        $this->transaction = $transaction;
    }

    /**
     * @return Composer
     */
    public function getComposer()
    {
        return $this->composer;
    }

    /**
     * @return IOInterface
     */
    public function getIO()
    {
        return $this->io;
    }

    /**
     * @return bool
     */
    public function isDevMode()
    {
        return $this->devMode;
    }

    /**
     * @return PolicyInterface
     */
    public function getPolicy()
    {
        return $this->policy;
    }

    /**
     * @return RepositorySet
     */
    public function getRepositorySet()
    {
        return $this->repositorySet;
    }

    /**
     * @return Pool
     */
    public function getPool()
    {
        return $this->pool;
    }

    /**
     * @return Request
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * @return Transaction|null
     */
    public function getTransaction()
    {
        return $this->transaction;
    }
}
