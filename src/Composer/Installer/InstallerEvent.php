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
use Composer\DependencyResolver\Transaction;
use Composer\EventDispatcher\Event;
use Composer\IO\IOInterface;

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
     * @var bool
     */
    private $executeOperations;

    /**
     * @var Transaction
     */
    private $transaction;

    /**
     * Constructor.
     *
     * @param string      $eventName
     * @param Composer    $composer
     * @param IOInterface $io
     * @param bool        $devMode
     * @param bool        $executeOperations
     * @param Transaction $transaction
     */
    public function __construct($eventName, Composer $composer, IOInterface $io, $devMode, $executeOperations, Transaction $transaction)
    {
        parent::__construct($eventName);

        $this->composer = $composer;
        $this->io = $io;
        $this->devMode = $devMode;
        $this->executeOperations = $executeOperations;
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
     * @return bool
     */
    public function isExecutingOperations()
    {
        return $this->executeOperations;
    }

    /**
     * @return Transaction|null
     */
    public function getTransaction()
    {
        return $this->transaction;
    }
}
