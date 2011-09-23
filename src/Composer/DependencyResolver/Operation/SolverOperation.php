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

namespace Composer\DependencyResolver\Operation;

use Composer\Package\PackageInterface;

/**
 * Abstract solver operation class.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
abstract class SolverOperation implements OperationInterface
{
    protected $package;
    protected $reason;

    /**
     * Initializes operation.
     *
     * @param   PackageInterface    $package    package instance
     * @param   string              $reason     operation reason
     */
    public function __construct(PackageInterface $package, $reason = null)
    {
        $this->package = $package;
        $this->reason  = $reason;
    }

    /**
     * Returns package instance.
     *
     * @return  PackageInterface
     */
    public function getPackage()
    {
        return $this->package;
    }

    /**
     * Returns package type.
     *
     * @return  string
     */
    public function getPackageType()
    {
        return $this->package->getType();
    }

    /**
     * Returns operation reason.
     *
     * @return  string
     */
    public function getReason()
    {
        return $this->reason;
    }
}
