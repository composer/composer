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
class MarkAliasUninstalledOperation extends Operation implements OperationInterface
{
    const TYPE = 'markAliasUninstalled';

    /**
     * Initializes operation.
     *
     * @param AliasPackage $package package instance
     */
    public function __construct(AliasPackage $package)
    {
        parent::__construct($package);
    }

    /**
     * {@inheritDoc}
     */
    public function show($lock)
    {
        return 'Marking <info>'.$this->package->getPrettyName().'</info> (<comment>'.$this->package->getFullPrettyVersion().'</comment>) as uninstalled, alias of <info>'.$this->package->getAliasOf()->getPrettyName().'</info> (<comment>'.$this->package->getAliasOf()->getFullPrettyVersion().'</comment>)';
    }
}
