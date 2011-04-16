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

namespace Composer\Test\DependencyResolver;

use Composer\DependencyResolver\ArrayRepository;
use Composer\DependencyResolver\DefaultPolicy;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\MemoryPackage;
use Composer\DependencyResolver\PackageRelation;
use Composer\DependencyResolver\Solver;
use Composer\DependencyResolver\RelationConstraint\VersionConstraint;

class SolverTest extends \PHPUnit_Framework_TestCase
{
    public function testSolver()
    {
        $this->markTestIncomplete('incomplete');
        return;

        $pool = new Pool;

        $repoInstalled = new ArrayRepository;
        $repoInstalled->addPackage(new MemoryPackage('old', '1.0'));
        $repoInstalled->addPackage(new MemoryPackage('C', '1.0'));

        $repo = new ArrayRepository;
        $repo->addPackage($packageA = new MemoryPackage('A', '2.0'));
        $repo->addPackage($packageB = new MemoryPackage('B', '1.0'));
        $repo->addPackage($newPackageB = new MemoryPackage('B', '1.1'));
        $repo->addPackage($packageC = new MemoryPackage('C', '1.0'));
        $repo->addPackage($oldPackage = new MemoryPackage('old', '1.0'));
        $packageA->setRequires(array(new PackageRelation('A', 'B', new VersionConstraint('<', '1.1'), 'requires')));

        $pool->addRepository($repoInstalled);
        $pool->addRepository($repo);

        $request = new Request($pool);
        $request->install('A');
        $request->update('C');
        $request->remove('old');

        $policy = new DefaultPolicy;
        $solver = new Solver($policy, $pool, $repoInstalled);
        $result = $solver->solve($request);

        $this->assertTrue($result, 'Request could be solved');

        //$transaction = $solver->getTransaction();
        // assert ...
    }
}
