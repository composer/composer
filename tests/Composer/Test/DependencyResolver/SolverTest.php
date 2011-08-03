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

use Composer\Repository\ArrayRepository;
use Composer\Repository\PlatformRepository;
use Composer\DependencyResolver\DefaultPolicy;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\Solver;
use Composer\Package\MemoryPackage;
use Composer\Package\Link;
use Composer\Package\LinkConstraint\VersionConstraint;

class SolverTest extends \PHPUnit_Framework_TestCase
{
    public function testSolver()
    {
        $pool = new Pool;

        $repoInstalled = new ArrayRepository;
        $repoInstalled->addPackage($oldPackage = new MemoryPackage('old', '1.0'));
        $repoInstalled->addPackage($oldPackageC = new MemoryPackage('C', '1.0'));

        $repo = new ArrayRepository;
        $repo->addPackage($packageA = new MemoryPackage('A', '2.0'));
        $repo->addPackage($packageB = new MemoryPackage('B', '1.0'));
        $repo->addPackage($newPackageB = new MemoryPackage('B', '1.1'));
        $repo->addPackage($packageC = new MemoryPackage('C', '1.1'));
        $repo->addPackage(new MemoryPackage('old', '1.0'));
        $packageA->setRequires(array(new Link('A', 'B', new VersionConstraint('<', '1.1'), 'requires')));

        $pool->addRepository($repoInstalled);
        $pool->addRepository($repo);

        $request = new Request($pool);
        $request->install('A');
        //$request->update('C');
        //$request->remove('old');

        $policy = new DefaultPolicy;
        $solver = new Solver($policy, $pool, $repoInstalled);
        $result = $solver->solve($request);

        $expected = array(
            array(
                'job' => 'install',
                'package' => $packageA,
            ),
            /*array(
                'job' => 'remove',
                'package' => $oldPackage,
            ),*/
            array(
                'job' => 'install',
                'package' => $packageB,
            ),/*
            array(
                'job' => 'update',
                'package' => $packageC,
            ),*/
        );

        $this->assertEquals($expected, $result);
    }
}
