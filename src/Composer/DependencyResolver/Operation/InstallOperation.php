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

use Composer\Package\PackageInterface;

/**
 * Solver install operation.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class InstallOperation extends SolverOperation implements OperationInterface
{
    protected const TYPE = 'install';

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
    public function getPackage(): PackageInterface
    {
        return $this->package;
    }

    /**
     * @inheritDoc
     */
    public function show($lock): string
    {
        return self::format($this->package, $lock);
    }

    /**
     * @param bool $lock
     * @return string
     */
    public static function format(PackageInterface $package, bool $lock = false): string
    {
        return ($lock ? 'Locking ' : 'Installing ').'<info>'.$package->getPrettyName().'</info> (<comment>'.$package->getFullPrettyVersion().'</comment>)';
    }
}
