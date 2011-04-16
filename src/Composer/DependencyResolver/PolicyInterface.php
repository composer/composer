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

namespace Composer\DependencyResolver;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
interface PolicyInterface
{
    function allowUninstall();
    function allowDowngrade();
    function versionCompare(Package $a, Package $b, $operator);
    function findUpdatePackages(Solver $solver, Pool $pool, RepositoryInterface $repo, Package $package, $allowAll);
    function installable(Solver $solver, Pool $pool, RepositoryInterface $repo, Package $package);
    function selectPreferedPackages(array $literals);
}
