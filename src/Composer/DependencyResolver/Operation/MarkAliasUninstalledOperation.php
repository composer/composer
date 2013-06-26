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

/**
 * Solver install operation.
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class MarkAliasUninstalledOperation extends SolverOperation
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
     * Returns job type.
     *
     * @return string
     */
    public function getJobType()
    {
        return 'markAliasUninstalled';
    }

    /**
     * {@inheritDoc}
     */
    public function __toString()
    {
        return 'Marking '.$this->package->getPrettyName().' ('.$this->formatVersion($this->package).') as uninstalled, alias of '.$this->package->getAliasOf()->getPrettyName().' ('.$this->formatVersion($this->package->getAliasOf()).')';
    }
}
