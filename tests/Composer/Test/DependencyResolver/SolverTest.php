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

use Composer\IO\NullIO;
use Composer\Repository\ArrayRepository;
use Composer\Repository\LockArrayRepository;
use Composer\DependencyResolver\DefaultPolicy;
use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\Solver;
use Composer\DependencyResolver\SolverProblemsException;
use Composer\Package\Link;
use Composer\Repository\RepositorySet;
use Composer\Test\TestCase;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\Semver\Constraint\MatchAllConstraint;

class SolverTest extends TestCase
{
    protected $repoSet;
    protected $repo;
    protected $repoLocked;
    protected $request;
    protected $policy;
    protected $solver;
    protected $pool;

    public function setUp()
    {
        $this->repoSet = new RepositorySet();
        $this->repo = new ArrayRepository;
        $this->repoLocked = new LockArrayRepository;

        $this->request = new Request($this->repoLocked);
        $this->policy = new DefaultPolicy;
    }

    public function testSolverInstallSingle()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->reposComplete();

        $this->request->requireName('A');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testSolverRemoveIfNotRequested()
    {
        $this->repoLocked->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->reposComplete();

        $this->checkSolverResult(array(
            array('job' => 'remove', 'package' => $packageA),
        ));
    }

    public function testInstallNonExistingPackageFails()
    {
        $this->repo->addPackage($this->getPackage('A', '1.0'));
        $this->reposComplete();

        $this->request->requireName('B', $this->getVersionConstraint('==', '1'));

        $this->createSolver();
        try {
            $transaction = $this->solver->solve($this->request);
            $this->fail('Unsolvable conflict did not result in exception.');
        } catch (SolverProblemsException $e) {
            $problems = $e->getProblems();
            $this->assertCount(1, $problems);
            $this->assertEquals(2, $e->getCode());
            $this->assertEquals("\n    - Root composer.json requires b, it could not be found in any version, there may be a typo in the package name.", $problems[0]->getPrettyString($this->repoSet, $this->request, $this->pool, false));
        }
    }

    public function testSolverInstallSamePackageFromDifferentRepositories()
    {
        $repo1 = new ArrayRepository;
        $repo2 = new ArrayRepository;

        $repo1->addPackage($foo1 = $this->getPackage('foo', '1'));
        $repo2->addPackage($foo2 = $this->getPackage('foo', '1'));

        $this->repoSet->addRepository($repo1);
        $this->repoSet->addRepository($repo2);

        $this->request->requireName('foo');

        $this->checkSolverResult(array(
                array('job' => 'install', 'package' => $foo1),
        ));
    }

    public function testSolverInstallWithDeps()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '1.0'));
        $this->repo->addPackage($newPackageB = $this->getPackage('B', '1.1'));

        $packageA->setRequires(array('b' => new Link('A', 'B', $this->getVersionConstraint('<', '1.1'), Link::TYPE_REQUIRE)));

        $this->reposComplete();

        $this->request->requireName('A');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageB),
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testSolverInstallHonoursNotEqualOperator()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '1.0'));
        $this->repo->addPackage($newPackageB11 = $this->getPackage('B', '1.1'));
        $this->repo->addPackage($newPackageB12 = $this->getPackage('B', '1.2'));
        $this->repo->addPackage($newPackageB13 = $this->getPackage('B', '1.3'));

        $packageA->setRequires(array(
            'b' => new Link('A', 'B', new MultiConstraint(array(
                $this->getVersionConstraint('<=', '1.3'),
                $this->getVersionConstraint('<>', '1.3'),
                $this->getVersionConstraint('!=', '1.2'),
            )), Link::TYPE_REQUIRE),
        ));

        $this->reposComplete();

        $this->request->requireName('A');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $newPackageB11),
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testSolverInstallWithDepsInOrder()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '1.0'));
        $this->repo->addPackage($packageC = $this->getPackage('C', '1.0'));

        $packageB->setRequires(array(
            'a' => new Link('B', 'A', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
            'c' => new Link('B', 'C', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
        ));
        $packageC->setRequires(array(
            'a' => new Link('C', 'A', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
        ));

        $this->reposComplete();

        $this->request->requireName('A');
        $this->request->requireName('B');
        $this->request->requireName('C');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageA),
            array('job' => 'install', 'package' => $packageC),
            array('job' => 'install', 'package' => $packageB),
        ));
    }

    public function testSolverFixLocked()
    {
        $this->repoLocked->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->reposComplete();

        $this->request->fixPackage($packageA);

        $this->checkSolverResult(array());
    }

    public function testSolverFixLockedWithAlternative()
    {
        $this->repo->addPackage($this->getPackage('A', '1.0'));
        $this->repoLocked->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->reposComplete();

        $this->request->fixPackage($packageA);

        $this->checkSolverResult(array());
    }

    public function testSolverUpdateDoesOnlyUpdate()
    {
        $this->repoLocked->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repoLocked->addPackage($packageB = $this->getPackage('B', '1.0'));
        $this->repo->addPackage($newPackageB = $this->getPackage('B', '1.1'));
        $this->reposComplete();

        $packageA->setRequires(array('b' => new Link('A', 'B', $this->getVersionConstraint('>=', '1.0.0.0'), Link::TYPE_REQUIRE)));

        $this->request->fixPackage($packageA);
        $this->request->requireName('B', $this->getVersionConstraint('=', '1.1.0.0'));

        $this->checkSolverResult(array(
            array('job' => 'update', 'from' => $packageB, 'to' => $newPackageB),
        ));
    }

    public function testSolverUpdateSingle()
    {
        $this->repoLocked->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($newPackageA = $this->getPackage('A', '1.1'));
        $this->reposComplete();

        $this->request->requireName('A');

        $this->checkSolverResult(array(
            array('job' => 'update', 'from' => $packageA, 'to' => $newPackageA),
        ));
    }

    public function testSolverUpdateAll()
    {
        $this->repoLocked->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repoLocked->addPackage($packageB = $this->getPackage('B', '1.0'));
        $this->repo->addPackage($newPackageA = $this->getPackage('A', '1.1'));
        $this->repo->addPackage($newPackageB = $this->getPackage('B', '1.1'));

        $packageA->setRequires(array('b' => new Link('A', 'B', new MatchAllConstraint(), Link::TYPE_REQUIRE)));
        $newPackageA->setRequires(array('b' => new Link('A', 'B', new MatchAllConstraint(), Link::TYPE_REQUIRE)));

        $this->reposComplete();

        $this->request->requireName('A');

        $this->checkSolverResult(array(
            array('job' => 'update', 'from' => $packageB, 'to' => $newPackageB),
            array('job' => 'update', 'from' => $packageA, 'to' => $newPackageA),
        ));
    }

    public function testSolverUpdateCurrent()
    {
        $this->repoLocked->addPackage($this->getPackage('A', '1.0'));
        $this->repo->addPackage($this->getPackage('A', '1.0'));
        $this->reposComplete();

        $this->request->requireName('A');

        $this->checkSolverResult(array());
    }

    public function testSolverUpdateOnlyUpdatesSelectedPackage()
    {
        $this->repoLocked->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repoLocked->addPackage($packageB = $this->getPackage('B', '1.0'));
        $this->repo->addPackage($packageAnewer = $this->getPackage('A', '1.1'));
        $this->repo->addPackage($packageBnewer = $this->getPackage('B', '1.1'));

        $this->reposComplete();

        $this->request->requireName('A');
        $this->request->fixPackage($packageB);

        $this->checkSolverResult(array(
            array('job' => 'update', 'from' => $packageA, 'to' => $packageAnewer),
        ));
    }

    public function testSolverUpdateConstrained()
    {
        $this->repoLocked->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($newPackageA = $this->getPackage('A', '1.2'));
        $this->repo->addPackage($this->getPackage('A', '2.0'));
        $this->reposComplete();

        $this->request->requireName('A', $this->getVersionConstraint('<', '2.0.0.0'));

        $this->checkSolverResult(array(array(
            'job' => 'update',
            'from' => $packageA,
            'to' => $newPackageA,
        )));
    }

    public function testSolverUpdateFullyConstrained()
    {
        $this->repoLocked->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($newPackageA = $this->getPackage('A', '1.2'));
        $this->repo->addPackage($this->getPackage('A', '2.0'));
        $this->reposComplete();

        $this->request->requireName('A', $this->getVersionConstraint('<', '2.0.0.0'));

        $this->checkSolverResult(array(array(
            'job' => 'update',
            'from' => $packageA,
            'to' => $newPackageA,
        )));
    }

    public function testSolverUpdateFullyConstrainedPrunesInstalledPackages()
    {
        $this->repoLocked->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repoLocked->addPackage($packageB = $this->getPackage('B', '1.0'));
        $this->repo->addPackage($newPackageA = $this->getPackage('A', '1.2'));
        $this->repo->addPackage($this->getPackage('A', '2.0'));
        $this->reposComplete();

        $this->request->requireName('A', $this->getVersionConstraint('<', '2.0.0.0'));

        $this->checkSolverResult(array(
            array(
                'job' => 'remove',
                'package' => $packageB,
            ),
            array(
                'job' => 'update',
                'from' => $packageA,
                'to' => $newPackageA,
            ),
        ));
    }

    public function testSolverAllJobs()
    {
        $this->repoLocked->addPackage($packageD = $this->getPackage('D', '1.0'));
        $this->repoLocked->addPackage($oldPackageC = $this->getPackage('C', '1.0'));

        $this->repo->addPackage($packageA = $this->getPackage('A', '2.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '1.0'));
        $this->repo->addPackage($newPackageB = $this->getPackage('B', '1.1'));
        $this->repo->addPackage($packageC = $this->getPackage('C', '1.1'));
        $this->repo->addPackage($this->getPackage('D', '1.0'));
        $packageA->setRequires(array('b' => new Link('A', 'B', $this->getVersionConstraint('<', '1.1'), Link::TYPE_REQUIRE)));

        $this->reposComplete();

        $this->request->requireName('A');
        $this->request->requireName('C');

        $this->checkSolverResult(array(
            array('job' => 'remove',  'package' => $packageD),
            array('job' => 'install', 'package' => $packageB),
            array('job' => 'install', 'package' => $packageA),
            array('job' => 'update',  'from' => $oldPackageC, 'to' => $packageC),
        ));
    }

    public function testSolverThreeAlternativeRequireAndConflict()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '2.0'));
        $this->repo->addPackage($middlePackageB = $this->getPackage('B', '1.0'));
        $this->repo->addPackage($newPackageB = $this->getPackage('B', '1.1'));
        $this->repo->addPackage($oldPackageB = $this->getPackage('B', '0.9'));
        $packageA->setRequires(array('b' => new Link('A', 'B', $this->getVersionConstraint('<', '1.1'), Link::TYPE_REQUIRE)));
        $packageA->setConflicts(array('b' => new Link('A', 'B', $this->getVersionConstraint('<', '1.0'), Link::TYPE_CONFLICT)));

        $this->reposComplete();

        $this->request->requireName('A');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $middlePackageB),
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testSolverObsolete()
    {
        $this->repoLocked->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '1.0'));
        $packageB->setReplaces(array('a' => new Link('B', 'A', new MatchAllConstraint())));

        $this->reposComplete();

        $this->request->requireName('B');

        $this->checkSolverResult(array(
            array('job' => 'remove', 'package' => $packageA),
            array('job' => 'install', 'package' => $packageB),
        ));
    }

    public function testInstallOneOfTwoAlternatives()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('A', '1.0'));

        $this->reposComplete();

        $this->request->requireName('A');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testInstallProvider()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageQ = $this->getPackage('Q', '1.0'));
        $packageA->setRequires(array('b' => new Link('A', 'B', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE)));
        $packageQ->setProvides(array('b' => new Link('Q', 'B', $this->getVersionConstraint('=', '1.0'), Link::TYPE_PROVIDE)));

        $this->reposComplete();

        $this->request->requireName('A');

        // must explicitly pick the provider, so error in this case
        $this->setExpectedException('Composer\DependencyResolver\SolverProblemsException');
        $this->createSolver();
        $this->solver->solve($this->request);
    }

    public function testSkipReplacerOfExistingPackage()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageQ = $this->getPackage('Q', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '1.0'));
        $packageA->setRequires(array('b' => new Link('A', 'B', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE)));
        $packageQ->setReplaces(array('b' => new Link('Q', 'B', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REPLACE)));

        $this->reposComplete();

        $this->request->requireName('A');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageB),
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testNoInstallReplacerOfMissingPackage()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageQ = $this->getPackage('Q', '1.0'));
        $packageA->setRequires(array('b' => new Link('A', 'B', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE)));
        $packageQ->setReplaces(array('b' => new Link('Q', 'B', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REPLACE)));

        $this->reposComplete();

        $this->request->requireName('A');

        $this->setExpectedException('Composer\DependencyResolver\SolverProblemsException');
        $this->createSolver();
        $this->solver->solve($this->request);
    }

    public function testSkipReplacedPackageIfReplacerIsSelected()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageQ = $this->getPackage('Q', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '1.0'));
        $packageA->setRequires(array('b' => new Link('A', 'B', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE)));
        $packageQ->setReplaces(array('b' => new Link('Q', 'B', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REPLACE)));

        $this->reposComplete();

        $this->request->requireName('A');
        $this->request->requireName('Q');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageQ),
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testPickOlderIfNewerConflicts()
    {
        $this->repo->addPackage($packageX = $this->getPackage('X', '1.0'));
        $packageX->setRequires(array(
            'a' => new Link('X', 'A', $this->getVersionConstraint('>=', '2.0.0.0'), Link::TYPE_REQUIRE),
            'b' => new Link('X', 'B', $this->getVersionConstraint('>=', '2.0.0.0'), Link::TYPE_REQUIRE),
        ));

        $this->repo->addPackage($packageA = $this->getPackage('A', '2.0.0'));
        $this->repo->addPackage($newPackageA = $this->getPackage('A', '2.1.0'));
        $this->repo->addPackage($newPackageB = $this->getPackage('B', '2.1.0'));

        $packageA->setRequires(array('b' => new Link('A', 'B', $this->getVersionConstraint('>=', '2.0.0.0'), Link::TYPE_REQUIRE)));

        // new package A depends on version of package B that does not exist
        // => new package A is not installable
        $newPackageA->setRequires(array('b' => new Link('A', 'B', $this->getVersionConstraint('>=', '2.2.0.0'), Link::TYPE_REQUIRE)));

        // add a package S replacing both A and B, so that S and B or S and A cannot be simultaneously installed
        // but an alternative option for A and B both exists
        // this creates a more difficult so solve conflict
        $this->repo->addPackage($packageS = $this->getPackage('S', '2.0.0'));
        $packageS->setReplaces(array(
            'a' => new Link('S', 'A', $this->getVersionConstraint('>=', '2.0.0.0'), Link::TYPE_REPLACE),
            'b' => new Link('S', 'B', $this->getVersionConstraint('>=', '2.0.0.0'), Link::TYPE_REPLACE),
        ));

        $this->reposComplete();

        $this->request->requireName('X');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $newPackageB),
            array('job' => 'install', 'package' => $packageA),
            array('job' => 'install', 'package' => $packageX),
        ));
    }

    public function testInstallCircularRequire()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB1 = $this->getPackage('B', '0.9'));
        $this->repo->addPackage($packageB2 = $this->getPackage('B', '1.1'));
        $packageA->setRequires(array('b' => new Link('A', 'B', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE)));
        $packageB2->setRequires(array('a' => new Link('B', 'A', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE)));

        $this->reposComplete();

        $this->request->requireName('A');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageB2),
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testInstallAlternativeWithCircularRequire()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '1.0'));
        $this->repo->addPackage($packageC = $this->getPackage('C', '1.0'));
        $this->repo->addPackage($packageD = $this->getPackage('D', '1.0'));
        $packageA->setRequires(array('b' => new Link('A', 'B', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE)));
        $packageB->setRequires(array('virtual' => new Link('B', 'Virtual', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE)));
        $packageC->setProvides(array('virtual' => new Link('C', 'Virtual', $this->getVersionConstraint('==', '1.0'), Link::TYPE_PROVIDE)));
        $packageD->setProvides(array('virtual' => new Link('D', 'Virtual', $this->getVersionConstraint('==', '1.0'), Link::TYPE_PROVIDE)));

        $packageC->setRequires(array('a' => new Link('C', 'A', $this->getVersionConstraint('==', '1.0'), Link::TYPE_REQUIRE)));
        $packageD->setRequires(array('a' => new Link('D', 'A', $this->getVersionConstraint('==', '1.0'), Link::TYPE_REQUIRE)));

        $this->reposComplete();

        $this->request->requireName('A');
        $this->request->requireName('C');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageB),
            array('job' => 'install', 'package' => $packageA),
            array('job' => 'install', 'package' => $packageC),
        ));
    }

    /**
     * If a replacer D replaces B and C with C not otherwise available,
     * D must be installed instead of the original B.
     */
    public function testUseReplacerIfNecessary()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '1.0'));
        $this->repo->addPackage($packageD = $this->getPackage('D', '1.0'));
        $this->repo->addPackage($packageD2 = $this->getPackage('D', '1.1'));

        $packageA->setRequires(array(
            'b' => new Link('A', 'B', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
            'c' => new Link('A', 'C', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
        ));

        $packageD->setReplaces(array(
            'b' => new Link('D', 'B', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REPLACE),
            'c' => new Link('D', 'C', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REPLACE),
        ));

        $packageD2->setReplaces(array(
            'b' => new Link('D', 'B', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REPLACE),
            'c' => new Link('D', 'C', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REPLACE),
        ));

        $this->reposComplete();

        $this->request->requireName('A');
        $this->request->requireName('D');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageD2),
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testIssue265()
    {
        $this->repo->addPackage($packageA1 = $this->getPackage('A', '2.0.999999-dev'));
        $this->repo->addPackage($packageA2 = $this->getPackage('A', '2.1-dev'));
        $this->repo->addPackage($packageA3 = $this->getPackage('A', '2.2-dev'));
        $this->repo->addPackage($packageB1 = $this->getPackage('B', '2.0.10'));
        $this->repo->addPackage($packageB2 = $this->getPackage('B', '2.0.9'));
        $this->repo->addPackage($packageC = $this->getPackage('C', '2.0-dev'));
        $this->repo->addPackage($packageD = $this->getPackage('D', '2.0.9'));

        $packageC->setRequires(array(
            'a' => new Link('C', 'A', $this->getVersionConstraint('>=', '2.0'), Link::TYPE_REQUIRE),
            'd' => new Link('C', 'D', $this->getVersionConstraint('>=', '2.0'), Link::TYPE_REQUIRE),
        ));

        $packageD->setRequires(array(
            'a' => new Link('D', 'A', $this->getVersionConstraint('>=', '2.1'), Link::TYPE_REQUIRE),
            'b' => new Link('D', 'B', $this->getVersionConstraint('>=', '2.0-dev'), Link::TYPE_REQUIRE),
        ));

        $packageB1->setRequires(array('a' => new Link('B', 'A', $this->getVersionConstraint('==', '2.1.0.0-dev'), Link::TYPE_REQUIRE)));
        $packageB2->setRequires(array('a' => new Link('B', 'A', $this->getVersionConstraint('==', '2.1.0.0-dev'), Link::TYPE_REQUIRE)));

        $packageB2->setReplaces(array('d' => new Link('B', 'D', $this->getVersionConstraint('==', '2.0.9.0'), Link::TYPE_REPLACE)));

        $this->reposComplete();

        $this->request->requireName('C', $this->getVersionConstraint('==', '2.0.0.0-dev'));

        $this->setExpectedException('Composer\DependencyResolver\SolverProblemsException');

        $this->createSolver();
        $this->solver->solve($this->request);
    }

    public function testConflictResultEmpty()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '1.0'));
        $packageA->setConflicts(array(
            'b' => new Link('A', 'B', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_CONFLICT),
        ));

        $this->reposComplete();

        $emptyConstraint = new MatchAllConstraint();
        $emptyConstraint->setPrettyString('*');

        $this->request->requireName('A', $emptyConstraint);
        $this->request->requireName('B', $emptyConstraint);

        $this->createSolver();
        try {
            $transaction = $this->solver->solve($this->request);
            $this->fail('Unsolvable conflict did not result in exception.');
        } catch (SolverProblemsException $e) {
            $problems = $e->getProblems();
            $this->assertCount(1, $problems);

            $msg = "\n";
            $msg .= "  Problem 1\n";
            $msg .= "    - Root composer.json requires a * -> satisfiable by A[1.0].\n";
            $msg .= "    - A 1.0 conflicts with B 1.0.\n";
            $msg .= "    - Root composer.json requires b * -> satisfiable by B[1.0].\n";
            $this->assertEquals($msg, $e->getPrettyString($this->repoSet, $this->request, $this->pool, false));
        }
    }

    public function testUnsatisfiableRequires()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '1.0'));

        $packageA->setRequires(array(
            'b' => new Link('A', 'B', $this->getVersionConstraint('>=', '2.0'), Link::TYPE_REQUIRE),
        ));

        $this->reposComplete();

        $this->request->requireName('A');

        $this->createSolver();
        try {
            $transaction = $this->solver->solve($this->request);
            $this->fail('Unsolvable conflict did not result in exception.');
        } catch (SolverProblemsException $e) {
            $problems = $e->getProblems();
            $this->assertCount(1, $problems);
            // TODO assert problem properties

            $msg = "\n";
            $msg .= "  Problem 1\n";
            $msg .= "    - Root composer.json requires a * -> satisfiable by A[1.0].\n";
            $msg .= "    - A 1.0 requires b >= 2.0 -> found B[1.0] but it does not match the constraint.\n";
            $this->assertEquals($msg, $e->getPrettyString($this->repoSet, $this->request, $this->pool, false));
        }
    }

    public function testRequireMismatchException()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '1.0'));
        $this->repo->addPackage($packageB2 = $this->getPackage('B', '0.9'));
        $this->repo->addPackage($packageC = $this->getPackage('C', '1.0'));
        $this->repo->addPackage($packageD = $this->getPackage('D', '1.0'));

        $packageA->setRequires(array(
            'b' => new Link('A', 'B', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
        ));
        $packageB->setRequires(array(
            'c' => new Link('B', 'C', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
        ));
        $packageC->setRequires(array(
            'd' => new Link('C', 'D', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
        ));
        $packageD->setRequires(array(
            'b' => new Link('D', 'B', $this->getVersionConstraint('<', '1.0'), Link::TYPE_REQUIRE),
        ));

        $this->reposComplete();

        $emptyConstraint = new MatchAllConstraint();
        $emptyConstraint->setPrettyString('*');

        $this->request->requireName('A', $emptyConstraint);

        $this->createSolver();
        try {
            $transaction = $this->solver->solve($this->request);
            $this->fail('Unsolvable conflict did not result in exception.');
        } catch (SolverProblemsException $e) {
            $problems = $e->getProblems();
            $this->assertCount(1, $problems);

            $msg = "\n";
            $msg .= "  Problem 1\n";
            $msg .= "    - C 1.0 requires d >= 1.0 -> satisfiable by D[1.0].\n";
            $msg .= "    - D 1.0 requires b < 1.0 -> satisfiable by B[0.9].\n";
            $msg .= "    - B 1.0 requires c >= 1.0 -> satisfiable by C[1.0].\n";
            $msg .= "    - You can only install one version of a package, so only one of these can be installed: B[0.9, 1.0].\n";
            $msg .= "    - A 1.0 requires b >= 1.0 -> satisfiable by B[1.0].\n";
            $msg .= "    - Root composer.json requires a * -> satisfiable by A[1.0].\n";
            $this->assertEquals($msg, $e->getPrettyString($this->repoSet, $this->request, $this->pool, false));
        }
    }

    public function testLearnLiteralsWithSortedRuleLiterals()
    {
        $this->repo->addPackage($packageTwig2 = $this->getPackage('twig/twig', '2.0'));
        $this->repo->addPackage($packageTwig16 = $this->getPackage('twig/twig', '1.6'));
        $this->repo->addPackage($packageTwig15 = $this->getPackage('twig/twig', '1.5'));
        $this->repo->addPackage($packageSymfony = $this->getPackage('symfony/symfony', '2.0'));
        $this->repo->addPackage($packageTwigBridge = $this->getPackage('symfony/twig-bridge', '2.0'));

        $packageTwigBridge->setRequires(array(
            'twig/twig' => new Link('symfony/twig-bridge', 'twig/twig', $this->getVersionConstraint('<', '2.0'), Link::TYPE_REQUIRE),
        ));

        $packageSymfony->setReplaces(array(
            'symfony/twig-bridge' => new Link('symfony/symfony', 'symfony/twig-bridge', $this->getVersionConstraint('==', '2.0'), Link::TYPE_REPLACE),
        ));

        $this->reposComplete();

        $this->request->requireName('symfony/twig-bridge');
        $this->request->requireName('twig/twig');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageTwig16),
            array('job' => 'install', 'package' => $packageTwigBridge),
        ));
    }

    public function testInstallRecursiveAliasDependencies()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '2.0'));
        $this->repo->addPackage($packageA2 = $this->getPackage('A', '2.0'));

        $packageA2->setRequires(array(
            'b' => new Link('A', 'B', $this->getVersionConstraint('==', '2.0'), Link::TYPE_REQUIRE, '== 2.0'),
        ));
        $packageB->setRequires(array(
            'a' => new Link('B', 'A', $this->getVersionConstraint('>=', '2.0'), Link::TYPE_REQUIRE),
        ));

        $this->repo->addPackage($packageA2Alias = $this->getAliasPackage($packageA2, '1.1'));

        $this->reposComplete();

        $this->request->requireName('A', $this->getVersionConstraint('==', '1.1.0.0'));

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageB),
            array('job' => 'install', 'package' => $packageA2),
            array('job' => 'markAliasInstalled', 'package' => $packageA2Alias),
        ));
    }

    public function testInstallDevAlias()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '2.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '1.0'));

        $packageB->setRequires(array(
            'a' => new Link('B', 'A', $this->getVersionConstraint('<', '2.0'), Link::TYPE_REQUIRE),
        ));

        $this->repo->addPackage($packageAAlias = $this->getAliasPackage($packageA, '1.1'));

        $this->reposComplete();

        $this->request->requireName('A', $this->getVersionConstraint('==', '2.0'));
        $this->request->requireName('B');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageA),
            array('job' => 'markAliasInstalled', 'package' => $packageAAlias),
            array('job' => 'install', 'package' => $packageB),
        ));
    }

    public function testInstallRootAliasesIfAliasOfIsInstalled()
    {
        // root aliased, required
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageAAlias = $this->getAliasPackage($packageA, '1.1'));
        $packageAAlias->setRootPackageAlias(true);
        // root aliased, not required, should still be installed as it is root alias
        $this->repo->addPackage($packageB = $this->getPackage('B', '1.0'));
        $this->repo->addPackage($packageBAlias = $this->getAliasPackage($packageB, '1.1'));
        $packageBAlias->setRootPackageAlias(true);
        // regular alias, not required, alias should not be installed
        $this->repo->addPackage($packageC = $this->getPackage('C', '1.0'));
        $this->repo->addPackage($packageCAlias = $this->getAliasPackage($packageC, '1.1'));

        $this->reposComplete();

        $this->request->requireName('A', $this->getVersionConstraint('==', '1.1'));
        $this->request->requireName('B', $this->getVersionConstraint('==', '1.0'));
        $this->request->requireName('C', $this->getVersionConstraint('==', '1.0'));

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageA),
            array('job' => 'markAliasInstalled', 'package' => $packageAAlias),
            array('job' => 'install', 'package' => $packageB),
            array('job' => 'markAliasInstalled', 'package' => $packageBAlias),
            array('job' => 'install', 'package' => $packageC),
            array('job' => 'markAliasInstalled', 'package' => $packageCAlias),
        ));
    }

    /**
     * Tests for a bug introduced in commit 451bab1c2cd58e05af6e21639b829408ad023463 Solver.php line 554/523
     *
     * Every package and link in this test matters, only a combination this complex will run into the situation in which
     * a negatively decided literal will need to be learned inverted as a positive assertion.
     *
     * In particular in this case the goal is to first have the solver decide X 2.0 should not be installed to later
     * decide to learn that X 2.0 must be installed and revert decisions to retry solving with this new assumption.
     */
    public function testLearnPositiveLiteral()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '1.0'));
        $this->repo->addPackage($packageC1 = $this->getPackage('C', '1.0'));
        $this->repo->addPackage($packageC2 = $this->getPackage('C', '2.0'));
        $this->repo->addPackage($packageD = $this->getPackage('D', '1.0'));
        $this->repo->addPackage($packageE = $this->getPackage('E', '1.0'));
        $this->repo->addPackage($packageF1 = $this->getPackage('F', '1.0'));
        $this->repo->addPackage($packageF2 = $this->getPackage('F', '2.0'));
        $this->repo->addPackage($packageG1 = $this->getPackage('G', '1.0'));
        $this->repo->addPackage($packageG2 = $this->getPackage('G', '2.0'));
        $this->repo->addPackage($packageG3 = $this->getPackage('G', '3.0'));

        $packageA->setRequires(array(
            'b' => new Link('A', 'B', $this->getVersionConstraint('==', '1.0'), Link::TYPE_REQUIRE),
            'c' => new Link('A', 'C', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
            'd' => new Link('A', 'D', $this->getVersionConstraint('==', '1.0'), Link::TYPE_REQUIRE),
        ));

        $packageB->setRequires(array(
            'e' => new Link('B', 'E', $this->getVersionConstraint('==', '1.0'), Link::TYPE_REQUIRE),
        ));

        $packageC1->setRequires(array(
            'f' => new Link('C', 'F', $this->getVersionConstraint('==', '1.0'), Link::TYPE_REQUIRE),
        ));
        $packageC2->setRequires(array(
            'f' => new Link('C', 'F', $this->getVersionConstraint('==', '1.0'), Link::TYPE_REQUIRE),
            'g' => new Link('C', 'G', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
        ));

        $packageD->setRequires(array(
            'f' => new Link('D', 'F', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
        ));

        $packageE->setRequires(array(
            'g' => new Link('E', 'G', $this->getVersionConstraint('<=', '2.0'), Link::TYPE_REQUIRE),
        ));

        $this->reposComplete();

        $this->request->requireName('A');

        $this->createSolver();

        // check correct setup for assertion later
        $this->assertFalse($this->solver->testFlagLearnedPositiveLiteral);

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageF1),
            array('job' => 'install', 'package' => $packageD),
            array('job' => 'install', 'package' => $packageG2),
            array('job' => 'install', 'package' => $packageC2),
            array('job' => 'install', 'package' => $packageE),
            array('job' => 'install', 'package' => $packageB),
            array('job' => 'install', 'package' => $packageA),
        ));

        // verify that the code path leading to a negative literal resulting in a positive learned literal is actually
        // executed
        $this->assertTrue($this->solver->testFlagLearnedPositiveLiteral);
    }

    protected function reposComplete()
    {
        $this->repoSet->addRepository($this->repo);
        $this->repoSet->addRepository($this->repoLocked);
    }

    protected function createSolver()
    {
        $io = new NullIO();
        $this->pool = $this->repoSet->createPool($this->request, $io);
        $this->solver = new Solver($this->policy, $this->pool, $io);
    }

    protected function checkSolverResult(array $expected)
    {
        $this->createSolver();
        $transaction = $this->solver->solve($this->request);

        $result = array();
        foreach ($transaction->getOperations() as $operation) {
            if ('update' === $operation->getOperationType()) {
                $result[] = array(
                    'job' => 'update',
                    'from' => $operation->getInitialPackage(),
                    'to' => $operation->getTargetPackage(),
                );
            } elseif (in_array($operation->getOperationType(), array('markAliasInstalled', 'markAliasUninstalled'))) {
                $result[] = array(
                    'job' => $operation->getOperationType(),
                    'package' => $operation->getPackage(),
                );
            } else {
                $job = ('uninstall' === $operation->getOperationType() ? 'remove' : 'install');
                $result[] = array(
                    'job' => $job,
                    'package' => $operation->getPackage(),
                );
            }
        }

        $expectedReadable = array();
        foreach ($expected as $op) {
            $expectedReadable[] = array_map('strval', $op);
        }
        $resultReadable = array();
        foreach ($result as $op) {
            $resultReadable[] = array_map('strval', $op);
        }

        $this->assertEquals($expectedReadable, $resultReadable);
        $this->assertEquals($expected, $result);
    }
}
