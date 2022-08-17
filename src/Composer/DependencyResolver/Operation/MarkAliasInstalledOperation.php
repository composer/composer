<?php declare(strict_types=1);

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
class MarkAliasInstalledOperation extends SolverOperation implements OperationInterface
{
    protected const TYPE = 'markAliasInstalled';

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
     */
    public function getPackage(): AliasPackage
    {
        return $this->package;
    }

    /**
     * @inheritDoc
     */
    public function show($lock): string
    {
        return 'Marking <info>'.$this->package->getPrettyName().'</info> (<comment>'.$this->package->getFullPrettyVersion().'</comment>) as installed, alias of <info>'.$this->package->getAliasOf()->getPrettyName().'</info> (<comment>'.$this->package->getAliasOf()->getFullPrettyVersion().'</comment>)';
    }
}
