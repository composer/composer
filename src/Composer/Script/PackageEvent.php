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

namespace Composer\Script;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\DependencyResolver\Operation\OperationInterface;

/**
 * The Package Event.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PackageEvent extends Event
{
    /**
     * @var OperationInterface The package instance
     */
    private $operation;

    /**
     * Constructor.
     *
     * @param string             $name      The event name
     * @param Composer           $composer  The composer object
     * @param IOInterface        $io        The IOInterface object
     * @param boolean            $devMode   Whether or not we are in dev mode
     * @param OperationInterface $operation The operation object
     */
    public function __construct($name, Composer $composer, IOInterface $io, $devMode, OperationInterface $operation)
    {
        parent::__construct($name, $composer, $io, $devMode);
        $this->operation = $operation;
    }

    /**
     * Returns the package instance.
     *
     * @return OperationInterface
     */
    public function getOperation()
    {
        return $this->operation;
    }
}
