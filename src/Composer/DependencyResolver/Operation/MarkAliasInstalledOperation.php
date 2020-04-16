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

use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;

/**
 * Solver install operation.
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class MarkAliasInstalledOperation extends SolverOperation
{
    protected $package;

    /**
     * Initializes operation.
     *
     * @param AliasPackage $package package instance
     * @param string       $reason  operation reason
     */
    public function __construct(AliasPackage $package, $reason = null)
    {
        parent::__construct($reason);

        $this->package = $package;
    }

    /**
     * Returns package instance.
     *
     * @return PackageInterface
     */
    public function getPackage()
    {
        return $this->package;
    }

    /**
     * Returns operation type.
     *
     * @return string
     */
    public function getOperationType()
    {
        return 'markAliasInstalled';
    }

    /**
     * {@inheritDoc}
     */
    public function show($lock)
    {
        return 'Marking <info>'.$this->package->getPrettyName().'</info> (<comment>'.$this->package->getFullPrettyVersion().'</comment>) as installed, alias of <info>'.$this->package->getAliasOf()->getPrettyName().'</info> (<comment>'.$this->package->getAliasOf()->getFullPrettyVersion().'</comment>)';
    }

    /**
     * {@inheritDoc}
     */
    public function __toString()
    {
        return $this->show(false);
    }
}
