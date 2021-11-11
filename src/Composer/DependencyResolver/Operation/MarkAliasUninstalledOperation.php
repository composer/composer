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
class MarkAliasUninstalledOperation extends SolverOperation implements OperationInterface
{
    const TYPE = 'markAliasUninstalled';

    /**
     * @var AliasPackage
     */
    protected $package;

    public function __construct(AliasPackage $package)
    {
        $this->package = $package;
    }

    /**
     * Returns package instance.
     *
     * @return AliasPackage
     */
    public function getPackage()
    {
        return $this->package;
    }

    /**
     * @inheritDoc
     */
    public function show($lock)
    {
        return 'Marking <info>'.$this->package->getPrettyName().'</info> (<comment>'.$this->package->getFullPrettyVersion().'</comment>) as uninstalled, alias of <info>'.$this->package->getAliasOf()->getPrettyName().'</info> (<comment>'.$this->package->getAliasOf()->getFullPrettyVersion().'</comment>)';
    }
}
