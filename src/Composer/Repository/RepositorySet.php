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

namespace Composer\Repository;

use Composer\DependencyResolver\Pool;
use Composer\Package\BasePackage;
use Composer\Package\Version\VersionParser;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Semver\Constraint\ConstraintInterface;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class RepositorySet
{
    private $pool;

    public function __construct(Pool $pool)
    {
        $this->pool = $pool;
    }

    public function addRepository(RepositoryInterface $repo, $rootAliases = array())
    {
        return $this->pool->addRepository($repo, $rootAliases);
    }

    public function isPackageAcceptable($name, $stability)
    {
        return $this->pool->isPackageAcceptable($name, $stability);
    }

    public function findPackages($name, ConstraintInterface $constraint = null)
    {
        return $this->pool->whatProvides($name, $constraint, true);
    }

    public function createPool()
    {
        return $this->pool;
    }

    // TODO get rid of this function
    public function getPoolTemp()
    {
        return $this->pool;
    }
}
