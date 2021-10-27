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
 * Solver uninstall operation.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class UninstallOperation extends SolverOperation implements OperationInterface
{
    const TYPE = 'uninstall';

    /**
     * @var PackageInterface
     */
    protected $package;

    public function __construct(PackageInterface $package)
    {
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
     * @inheritDoc
     */
    public function show($lock)
    {
        return self::format($this->package, $lock);
    }

    /**
     * @param bool $lock
     * @return string
     */
    public static function format(PackageInterface $package, $lock = false)
    {
        return 'Removing <info>'.$package->getPrettyName().'</info> (<comment>'.$package->getFullPrettyVersion().'</comment>)';
    }
}
