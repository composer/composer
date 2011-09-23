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
 * Solver install operation.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class InstallOperation extends SolverOperation
{
    /**
     * Returns job type.
     *
     * @return  string
     */
    public function getJobType()
    {
        return 'install';
    }
}
