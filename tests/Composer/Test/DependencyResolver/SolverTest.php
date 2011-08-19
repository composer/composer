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
use Composer\Repository\ComposerRepository;
use Composer\DependencyResolver\DefaultPolicy;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\Solver;
use Composer\Package\MemoryPackage;
use Composer\Package\Link;
use Composer\Package\LinkConstraint\VersionConstraint;

class SolverTest extends \PHPUnit_Framework_TestCase
{
    protected $pool;
    protected $repo;
    protected $repoInstalled;
    protected $request;
    protected $policy;

    public function setUp()
    {
        $this->pool = new Pool;
        $this->repo = new ArrayRepository;
        $this->repoInstalled = new ArrayRepository;

        $this->request = new Request($this->pool);
        $this->policy = new DefaultPolicy;
        $this->solver = new Solver($this->policy, $this->pool, $this->repoInstalled);
    }

    public function testSolverInstallSingle()
    {
        $this->repo->addPackage($packageA = new MemoryPackage('A', '1.0'));
        $this->reposComplete();

        $this->request->install('A');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testSolverInstallWithDeps()
    {
        $this->repo->addPackage($packageA = new MemoryPackage('A', '1.0'));
        $this->repo->addPackage($packageB = new MemoryPackage('B', '1.0'));
        $this->repo->addPackage($newPackageB = new MemoryPackage('B', '1.1'));

        $packageA->setRequires(array(new Link('A', 'B', new VersionConstraint('<', '1.1'), 'requires')));

        $this->reposComplete();

        $this->request->install('A');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageB),
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testSolverInstallInstalled()
    {
        $this->repo->addPackage(new MemoryPackage('A', '1.0'));
        $this->repoInstalled->addPackage(new MemoryPackage('A', '1.0'));
        $this->reposComplete();

        $this->request->install('A');

        $this->checkSolverResult(array());
    }

    public function testSolverRemoveSingle()
    {
        $this->repoInstalled->addPackage($packageA = new MemoryPackage('A', '1.0'));
        $this->reposComplete();

        $this->request->remove('A');

        $this->checkSolverResult(array(
            array('job' => 'remove', 'package' => $packageA),
        ));
    }

    public function testSolverRemoveUninstalled()
    {
        $this->repo->addPackage(new MemoryPackage('A', '1.0'));
        $this->reposComplete();

        $this->request->remove('A');

        $this->checkSolverResult(array());
    }

    public function testSolverUpdateSingle()
    {
        $this->markTestIncomplete();

        $this->repoInstalled->addPackage($packageA = new MemoryPackage('A', '1.0'));
        $this->repo->addPackage($newPackageA = new MemoryPackage('A', '1.1'));
        $this->reposComplete();

        $this->request->update('A');

        $this->checkSolverResult(array(
            array('job' => 'update', 'package' => $newPackageA),
        ));
    }

    public function testSolverUpdateCurrent()
    {
        $this->repoInstalled->addPackage(new MemoryPackage('A', '1.0'));
        $this->repo->addPackage(new MemoryPackage('A', '1.0'));
        $this->reposComplete();

        $this->request->update('A');

        $this->checkSolverResult(array());
    }

    public function testSolverFull()
    {
        $this->markTestIncomplete();

        $this->repoInstalled->addPackage($packageD = new MemoryPackage('D', '1.0'));
        $this->repoInstalled->addPackage($oldPackageC = new MemoryPackage('C', '1.0'));

        $this->repo->addPackage($packageA = new MemoryPackage('A', '2.0'));
        $this->repo->addPackage($packageB = new MemoryPackage('B', '1.0'));
        $this->repo->addPackage($newPackageB = new MemoryPackage('B', '1.1'));
        $this->repo->addPackage($packageC = new MemoryPackage('C', '1.1'));
        $this->repo->addPackage(new MemoryPackage('D', '1.0'));
        $packageA->setRequires(array(new Link('A', 'B', new VersionConstraint('<', '1.1'), 'requires')));

        $this->reposComplete();

        $this->request->install('A');
        $this->request->update('C');
        $this->request->remove('D');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageB),
            array('job' => 'install', 'package' => $packageA),
            array('job' => 'remove',  'package' => $packageD),
            array('job' => 'update',  'package' => $packageC),
        ));
    }

    public function testSolverWithComposerRepo()
    {
        $this->markTestIncomplete();

        $this->repoInstalled = new PlatformRepository;
        $this->repo = new ComposerRepository('http://packagist.org');
        list($monolog) = $this->repo->getPackages();

        $this->reposComplete();

        $this->request->install('Monolog');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $monolog),
        ));
    }

    protected function reposComplete()
    {
        $this->pool->addRepository($this->repoInstalled);
        $this->pool->addRepository($this->repo);
    }

    protected function checkSolverResult(array $expected)
    {
        $result = $this->solver->solve($this->request);
        $this->assertEquals($expected, $result);
    }

}
