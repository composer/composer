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
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\MarkAliasInstalledOperation;
use Composer\DependencyResolver\Operation\MarkAliasUninstalledOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\Solver;
use Composer\DependencyResolver\SolverProblemsException;
use Composer\Package\Link;
use Composer\Repository\RepositorySet;
use Composer\Test\TestCase;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\DependencyResolver\Pool;

class SolverTest extends TestCase
{
    /** @var RepositorySet */
    protected $repoSet;
    /** @var ArrayRepository */
    protected $repo;
    /** @var LockArrayRepository */
    protected $repoLocked;
    /** @var Request */
    protected $request;
    /** @var DefaultPolicy */
    protected $policy;
    /** @var Solver|null */
    protected $solver;
    /** @var Pool */
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
        $this->repo->addPackage($packageA = $this->getPackage('a/a', '1.0'));
        $this->reposComplete();

        $this->request->requireName('a/a');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testSolverRemoveIfNotRequested()
    {
        $this->repoLocked->addPackage($packageA = $this->getPackage('a/a', '1.0'));
        $this->reposComplete();

        $this->checkSolverResult(array(
            array('job' => 'remove', 'package' => $packageA),
        ));
    }

    public function testInstallNonExistingPackageFails()
    {
        $this->repo->addPackage($this->getPackage('a/a', '1.0'));
        $this->reposComplete();

        $this->request->requireName('b/b', $this->getVersionConstraint('==', '1'));

        $this->createSolver();
        try {
            $transaction = $this->solver->solve($this->request);
            $this->fail('Unsolvable conflict did not result in exception.');
        } catch (SolverProblemsException $e) {
            $problems = $e->getProblems();
            $this->assertCount(1, $problems);
            $this->assertEquals(2, $e->getCode());
            $this->assertEquals("\n    - Root composer.json requires b/b, it could not be found in any version, there may be a typo in the package name.", $problems[0]->getPrettyString($this->repoSet, $this->request, $this->pool, false));
        }
    }

    public function testSolverInstallSamePackageFromDifferentRepositories()
    {
        $repo1 = new ArrayRepository;
        $repo2 = new ArrayRepository;

        $repo1->addPackage($foo1 = $this->getPackage('foo/foo', '1'));
        $repo2->addPackage($foo2 = $this->getPackage('foo/foo', '1'));

        $this->repoSet->addRepository($repo1);
        $this->repoSet->addRepository($repo2);

        $this->request->requireName('foo/foo');

        $this->checkSolverResult(array(
                array('job' => 'install', 'package' => $foo1),
        ));
    }

    public function testSolverInstallWithDeps()
    {
        $this->repo->addPackage($packageA = $this->getPackage('a/a', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('b/b', '1.0'));
        $this->repo->addPackage($newPackageB = $this->getPackage('b/b', '1.1'));

        $packageA->setRequires(array('b/b' => new Link('a/a', 'b/b', $this->getVersionConstraint('<', '1.1'), Link::TYPE_REQUIRE)));

        $this->reposComplete();

        $this->request->requireName('a/a');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageB),
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testSolverInstallHonoursNotEqualOperator()
    {
        $this->repo->addPackage($packageA = $this->getPackage('a/a', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('b/b', '1.0'));
        $this->repo->addPackage($newPackageB11 = $this->getPackage('b/b', '1.1'));
        $this->repo->addPackage($newPackageB12 = $this->getPackage('b/b', '1.2'));
        $this->repo->addPackage($newPackageB13 = $this->getPackage('b/b', '1.3'));

        $packageA->setRequires(array(
            'b/b' => new Link('a/a', 'b/b', new MultiConstraint(array(
                $this->getVersionConstraint('<=', '1.3'),
                $this->getVersionConstraint('<>', '1.3'),
                $this->getVersionConstraint('!=', '1.2'),
            )), Link::TYPE_REQUIRE),
        ));

        $this->reposComplete();

        $this->request->requireName('a/a');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $newPackageB11),
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testSolverInstallWithDepsInOrder()
    {
        $this->repo->addPackage($packageA = $this->getPackage('a/a', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('b/b', '1.0'));
        $this->repo->addPackage($packageC = $this->getPackage('c/c', '1.0'));

        $packageB->setRequires(array(
            'a/a' => new Link('b/b', 'a/a', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
            'c/c' => new Link('b/b', 'c/c', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
        ));
        $packageC->setRequires(array(
            'a/a' => new Link('c/c', 'a/a', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
        ));

        $this->reposComplete();

        $this->request->requireName('a/a');
        $this->request->requireName('b/b');
        $this->request->requireName('c/c');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageA),
            array('job' => 'install', 'package' => $packageC),
            array('job' => 'install', 'package' => $packageB),
        ));
    }

    /**
     * This test covers a particular behavior of the solver related to packages with the same name and version,
     * but different requirements on other packages.
     * Imagine you had multiple instances of packages (same name/version) with e.g. different dists depending on what other related package they were "built" for.
     *
     * An example people can probably relate to, so it was chosen here for better readability:
     * - PHP versions 8.0.10 and 7.4.23 could be a package
     * - ext-foobar 1.0.0 could be a package, but it must be built separately for each PHP x.y series
     * - thus each of the ext-foobar packages lists the "PHP" package as a dependency
     *
     * This is not something that can happen with packages on e.g. Packagist, but custom installers with custom repositories might do something like this;
     * in fact, some PaaSes do the exact thing above, installing binary builds of PHP and extensions as Composer packages with a custom installer in a separate step before the "userland" `composer install`.
     *
     * If version selectors are sufficiently permissive (e.g. "ourcustom/php":"*", "ourcustom/ext-foobar":"*"), then it may happen that the Solver won't pick the highest possible PHP version, as it has already settled on an "ext-foobar" (they're all the same version to the Solver, it doesn't know about the different requirements in each of the otherwise identical packages) if that was listed in "require" before "php".
     * That's "unfixable", and not even broken, behavior (what if the "ext-foobar" has higher versions for the lower "PHP"? who wins then? any combination of the packages is "correct"), but it shouldn't randomly change.
     * This test asserts this behavior to prevent regressions.
     *
     * CAUTION: IF THIS TEST EVER FAILS, SOLVER BEHAVIOR HAS CHANGED AND MAY BREAK DOWNSTREAM USERS
     */
    public function testSolverMultiPackageNameVersionResolutionDependsOnRequireOrder()
    {
        $this->repo->addPackage($php74 = $this->getPackage('ourcustom/PHP', '7.4.23'));
        $this->repo->addPackage($php80 = $this->getPackage('ourcustom/PHP', '8.0.10'));
        $this->repo->addPackage($extForPhp74 = $this->getPackage('ourcustom/ext-foobar', '1.0'));
        $this->repo->addPackage($extForPhp80 = $this->getPackage('ourcustom/ext-foobar', '1.0'));

        $extForPhp74->setRequires(array(
            'ourcustom/php' => new Link('ourcustom/ext-foobar', 'ourcustom/PHP', new MultiConstraint(array(
                $this->getVersionConstraint('>=', '7.4.0'),
                $this->getVersionConstraint('<', '7.5.0'),
            )), Link::TYPE_REQUIRE),
        ));
        $extForPhp80->setRequires(array(
            'ourcustom/php' => new Link('ourcustom/ext-foobar', 'ourcustom/PHP', new MultiConstraint(array(
                $this->getVersionConstraint('>=', '8.0.0'),
                $this->getVersionConstraint('<', '8.1.0'),
            )), Link::TYPE_REQUIRE),
        ));

        $this->reposComplete();

        $this->request->requireName('ourcustom/PHP');
        $this->request->requireName('ourcustom/ext-foobar');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $php80),
            array('job' => 'install', 'package' => $extForPhp80),
        ));

        // now we flip the requirements around: we request "ext-foobar" before "php"
        // because the ext-foobar package that requires php74 comes first in the repo, and the one that requires php80 second, the solver will pick the one for php74, and then, as it is a dependency, also php74
        // this is because both packages have the same name and version; just their requirements differ
        // and because no other constraint forces a particular version of package "php"
        $this->request = new Request($this->repoLocked);
        $this->request->requireName('ourcustom/ext-foobar');
        $this->request->requireName('ourcustom/PHP');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $php74),
            array('job' => 'install', 'package' => $extForPhp74),
        ));
    }

    /**
     * This test is almost the same as above, except we're inserting the package with the requirement on the other package in a different order, asserting that if that is done, the order of requirements no longer matters
     *
     * CAUTION: IF THIS TEST EVER FAILS, SOLVER BEHAVIOR HAS CHANGED AND MAY BREAK DOWNSTREAM USERS
     */
    public function testSolverMultiPackageNameVersionResolutionIsIndependentOfRequireOrderIfOrderedDescendingByRequirement()
    {
        $this->repo->addPackage($php74 = $this->getPackage('ourcustom/PHP', '7.4'));
        $this->repo->addPackage($php80 = $this->getPackage('ourcustom/PHP', '8.0'));
        $this->repo->addPackage($extForPhp80 = $this->getPackage('ourcustom/ext-foobar', '1.0')); // note we are inserting this one into the repo first, unlike in the previous test
        $this->repo->addPackage($extForPhp74 = $this->getPackage('ourcustom/ext-foobar', '1.0'));

        $extForPhp80->setRequires(array(
            'ourcustom/php' => new Link('ourcustom/ext-foobar', 'ourcustom/PHP', new MultiConstraint(array(
                $this->getVersionConstraint('>=', '8.0.0'),
                $this->getVersionConstraint('<', '8.1.0'),
            )), Link::TYPE_REQUIRE),
        ));
        $extForPhp74->setRequires(array(
            'ourcustom/php' => new Link('ourcustom/ext-foobar', 'ourcustom/PHP', new MultiConstraint(array(
                $this->getVersionConstraint('>=', '7.4.0'),
                $this->getVersionConstraint('<', '7.5.0'),
            )), Link::TYPE_REQUIRE),
        ));

        $this->reposComplete();

        $this->request->requireName('ourcustom/PHP');
        $this->request->requireName('ourcustom/ext-foobar');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $php80),
            array('job' => 'install', 'package' => $extForPhp80),
        ));

        // unlike in the previous test, the order of requirements no longer matters now
        $this->request = new Request($this->repoLocked);
        $this->request->requireName('ourcustom/ext-foobar');
        $this->request->requireName('ourcustom/PHP');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $php80),
            array('job' => 'install', 'package' => $extForPhp80),
        ));
    }

    public function testSolverFixLocked()
    {
        $this->repoLocked->addPackage($packageA = $this->getPackage('a/a', '1.0'));
        $this->reposComplete();

        $this->request->fixPackage($packageA);

        $this->checkSolverResult(array());
    }

    public function testSolverFixLockedWithAlternative()
    {
        $this->repo->addPackage($this->getPackage('a/a', '1.0'));
        $this->repoLocked->addPackage($packageA = $this->getPackage('a/a', '1.0'));
        $this->reposComplete();

        $this->request->fixPackage($packageA);

        $this->checkSolverResult(array());
    }

    public function testSolverUpdateDoesOnlyUpdate()
    {
        $this->repoLocked->addPackage($packageA = $this->getPackage('a/a', '1.0'));
        $this->repoLocked->addPackage($packageB = $this->getPackage('b/b', '1.0'));
        $this->repo->addPackage($newPackageB = $this->getPackage('b/b', '1.1'));
        $this->reposComplete();

        $packageA->setRequires(array('b/b' => new Link('a/a', 'b/b', $this->getVersionConstraint('>=', '1.0.0.0'), Link::TYPE_REQUIRE)));

        $this->request->fixPackage($packageA);
        $this->request->requireName('b/b', $this->getVersionConstraint('=', '1.1.0.0'));

        $this->checkSolverResult(array(
            array('job' => 'update', 'from' => $packageB, 'to' => $newPackageB),
        ));
    }

    public function testSolverUpdateSingle()
    {
        $this->repoLocked->addPackage($packageA = $this->getPackage('a/a', '1.0'));
        $this->repo->addPackage($newPackageA = $this->getPackage('a/a', '1.1'));
        $this->reposComplete();

        $this->request->requireName('a/a');

        $this->checkSolverResult(array(
            array('job' => 'update', 'from' => $packageA, 'to' => $newPackageA),
        ));
    }

    public function testSolverUpdateAll()
    {
        $this->repoLocked->addPackage($packageA = $this->getPackage('a/a', '1.0'));
        $this->repoLocked->addPackage($packageB = $this->getPackage('b/b', '1.0'));
        $this->repo->addPackage($newPackageA = $this->getPackage('a/a', '1.1'));
        $this->repo->addPackage($newPackageB = $this->getPackage('b/b', '1.1'));

        $packageA->setRequires(array('b/b' => new Link('a/a', 'b/b', new MatchAllConstraint(), Link::TYPE_REQUIRE)));
        $newPackageA->setRequires(array('b/b' => new Link('a/a', 'b/b', new MatchAllConstraint(), Link::TYPE_REQUIRE)));

        $this->reposComplete();

        $this->request->requireName('a/a');

        $this->checkSolverResult(array(
            array('job' => 'update', 'from' => $packageB, 'to' => $newPackageB),
            array('job' => 'update', 'from' => $packageA, 'to' => $newPackageA),
        ));
    }

    public function testSolverUpdateCurrent()
    {
        $this->repoLocked->addPackage($this->getPackage('a/a', '1.0'));
        $this->repo->addPackage($this->getPackage('a/a', '1.0'));
        $this->reposComplete();

        $this->request->requireName('a/a');

        $this->checkSolverResult(array());
    }

    public function testSolverUpdateOnlyUpdatesSelectedPackage()
    {
        $this->repoLocked->addPackage($packageA = $this->getPackage('a/a', '1.0'));
        $this->repoLocked->addPackage($packageB = $this->getPackage('b/b', '1.0'));
        $this->repo->addPackage($packageAnewer = $this->getPackage('a/a', '1.1'));
        $this->repo->addPackage($packageBnewer = $this->getPackage('b/b', '1.1'));

        $this->reposComplete();

        $this->request->requireName('a/a');
        $this->request->fixPackage($packageB);

        $this->checkSolverResult(array(
            array('job' => 'update', 'from' => $packageA, 'to' => $packageAnewer),
        ));
    }

    public function testSolverUpdateConstrained()
    {
        $this->repoLocked->addPackage($packageA = $this->getPackage('a/a', '1.0'));
        $this->repo->addPackage($newPackageA = $this->getPackage('a/a', '1.2'));
        $this->repo->addPackage($this->getPackage('a/a', '2.0'));
        $this->reposComplete();

        $this->request->requireName('a/a', $this->getVersionConstraint('<', '2.0.0.0'));

        $this->checkSolverResult(array(array(
            'job' => 'update',
            'from' => $packageA,
            'to' => $newPackageA,
        )));
    }

    public function testSolverUpdateFullyConstrained()
    {
        $this->repoLocked->addPackage($packageA = $this->getPackage('a/a', '1.0'));
        $this->repo->addPackage($newPackageA = $this->getPackage('a/a', '1.2'));
        $this->repo->addPackage($this->getPackage('a/a', '2.0'));
        $this->reposComplete();

        $this->request->requireName('a/a', $this->getVersionConstraint('<', '2.0.0.0'));

        $this->checkSolverResult(array(array(
            'job' => 'update',
            'from' => $packageA,
            'to' => $newPackageA,
        )));
    }

    public function testSolverUpdateFullyConstrainedPrunesInstalledPackages()
    {
        $this->repoLocked->addPackage($packageA = $this->getPackage('a/a', '1.0'));
        $this->repoLocked->addPackage($packageB = $this->getPackage('b/b', '1.0'));
        $this->repo->addPackage($newPackageA = $this->getPackage('a/a', '1.2'));
        $this->repo->addPackage($this->getPackage('a/a', '2.0'));
        $this->reposComplete();

        $this->request->requireName('a/a', $this->getVersionConstraint('<', '2.0.0.0'));

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
        $this->repoLocked->addPackage($packageD = $this->getPackage('d/d', '1.0'));
        $this->repoLocked->addPackage($oldPackageC = $this->getPackage('c/c', '1.0'));

        $this->repo->addPackage($packageA = $this->getPackage('a/a', '2.0'));
        $this->repo->addPackage($packageB = $this->getPackage('b/b', '1.0'));
        $this->repo->addPackage($newPackageB = $this->getPackage('b/b', '1.1'));
        $this->repo->addPackage($packageC = $this->getPackage('c/c', '1.1'));
        $this->repo->addPackage($this->getPackage('d/d', '1.0'));
        $packageA->setRequires(array('b/b' => new Link('a/a', 'b/b', $this->getVersionConstraint('<', '1.1'), Link::TYPE_REQUIRE)));

        $this->reposComplete();

        $this->request->requireName('a/a');
        $this->request->requireName('c/c');

        $this->checkSolverResult(array(
            array('job' => 'remove',  'package' => $packageD),
            array('job' => 'install', 'package' => $packageB),
            array('job' => 'install', 'package' => $packageA),
            array('job' => 'update',  'from' => $oldPackageC, 'to' => $packageC),
        ));
    }

    public function testSolverThreeAlternativeRequireAndConflict()
    {
        $this->repo->addPackage($packageA = $this->getPackage('a/a', '2.0'));
        $this->repo->addPackage($middlePackageB = $this->getPackage('b/b', '1.0'));
        $this->repo->addPackage($newPackageB = $this->getPackage('b/b', '1.1'));
        $this->repo->addPackage($oldPackageB = $this->getPackage('b/b', '0.9'));
        $packageA->setRequires(array('b/b' => new Link('a/a', 'b/b', $this->getVersionConstraint('<', '1.1'), Link::TYPE_REQUIRE)));
        $packageA->setConflicts(array('b/b' => new Link('a/a', 'b/b', $this->getVersionConstraint('<', '1.0'), Link::TYPE_CONFLICT)));

        $this->reposComplete();

        $this->request->requireName('a/a');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $middlePackageB),
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testSolverObsolete()
    {
        $this->repoLocked->addPackage($packageA = $this->getPackage('a/a', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('b/b', '1.0'));
        $packageB->setReplaces(array('a/a' => new Link('b/b', 'a/a', new MatchAllConstraint())));

        $this->reposComplete();

        $this->request->requireName('b/b');

        $this->checkSolverResult(array(
            array('job' => 'remove', 'package' => $packageA),
            array('job' => 'install', 'package' => $packageB),
        ));
    }

    public function testInstallOneOfTwoAlternatives()
    {
        $this->repo->addPackage($packageA = $this->getPackage('a/a', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('a/a', '1.0'));

        $this->reposComplete();

        $this->request->requireName('a/a');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testInstallProvider()
    {
        $this->repo->addPackage($packageA = $this->getPackage('a/a', '1.0'));
        $this->repo->addPackage($packageQ = $this->getPackage('q/q', '1.0'));
        $packageA->setRequires(array('b/b' => new Link('a/a', 'b/b', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE)));
        $packageQ->setProvides(array('b/b' => new Link('q/q', 'b/b', $this->getVersionConstraint('=', '1.0'), Link::TYPE_PROVIDE)));

        $this->reposComplete();

        $this->request->requireName('a/a');

        // must explicitly pick the provider, so error in this case
        $this->setExpectedException('Composer\DependencyResolver\SolverProblemsException');
        $this->createSolver();
        $this->solver->solve($this->request);
    }

    public function testSkipReplacerOfExistingPackage()
    {
        $this->repo->addPackage($packageA = $this->getPackage('a/a', '1.0'));
        $this->repo->addPackage($packageQ = $this->getPackage('q/q', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('b/b', '1.0'));
        $packageA->setRequires(array('b/b' => new Link('a/a', 'b/b', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE)));
        $packageQ->setReplaces(array('b/b' => new Link('q/q', 'b/b', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REPLACE)));

        $this->reposComplete();

        $this->request->requireName('a/a');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageB),
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testNoInstallReplacerOfMissingPackage()
    {
        $this->repo->addPackage($packageA = $this->getPackage('a/a', '1.0'));
        $this->repo->addPackage($packageQ = $this->getPackage('q/q', '1.0'));
        $packageA->setRequires(array('b/b' => new Link('a/a', 'b/b', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE)));
        $packageQ->setReplaces(array('b/b' => new Link('q/q', 'b/b', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REPLACE)));

        $this->reposComplete();

        $this->request->requireName('a/a');

        $this->setExpectedException('Composer\DependencyResolver\SolverProblemsException');
        $this->createSolver();
        $this->solver->solve($this->request);
    }

    public function testSkipReplacedPackageIfReplacerIsSelected()
    {
        $this->repo->addPackage($packageA = $this->getPackage('a/a', '1.0'));
        $this->repo->addPackage($packageQ = $this->getPackage('q/q', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('b/b', '1.0'));
        $packageA->setRequires(array('b/b' => new Link('a/a', 'b/b', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE)));
        $packageQ->setReplaces(array('b/b' => new Link('q/q', 'b/b', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REPLACE)));

        $this->reposComplete();

        $this->request->requireName('a/a');
        $this->request->requireName('q/q');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageQ),
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testPickOlderIfNewerConflicts()
    {
        $this->repo->addPackage($packageX = $this->getPackage('x/x', '1.0'));
        $packageX->setRequires(array(
            'a/a' => new Link('x/x', 'a/a', $this->getVersionConstraint('>=', '2.0.0.0'), Link::TYPE_REQUIRE),
            'b/b' => new Link('x/x', 'b/b', $this->getVersionConstraint('>=', '2.0.0.0'), Link::TYPE_REQUIRE),
        ));

        $this->repo->addPackage($packageA = $this->getPackage('a/a', '2.0.0'));
        $this->repo->addPackage($newPackageA = $this->getPackage('a/a', '2.1.0'));
        $this->repo->addPackage($newPackageB = $this->getPackage('b/b', '2.1.0'));

        $packageA->setRequires(array('b/b' => new Link('a/a', 'b/b', $this->getVersionConstraint('>=', '2.0.0.0'), Link::TYPE_REQUIRE)));

        // new package A depends on version of package B that does not exist
        // => new package A is not installable
        $newPackageA->setRequires(array('b/b' => new Link('a/a', 'b/b', $this->getVersionConstraint('>=', '2.2.0.0'), Link::TYPE_REQUIRE)));

        // add a package S replacing both A and B, so that S and B or S and A cannot be simultaneously installed
        // but an alternative option for A and B both exists
        // this creates a more difficult so solve conflict
        $this->repo->addPackage($packageS = $this->getPackage('s/s', '2.0.0'));
        $packageS->setReplaces(array(
            'a/a' => new Link('s/s', 'a/a', $this->getVersionConstraint('>=', '2.0.0.0'), Link::TYPE_REPLACE),
            'b/b' => new Link('s/s', 'b/b', $this->getVersionConstraint('>=', '2.0.0.0'), Link::TYPE_REPLACE),
        ));

        $this->reposComplete();

        $this->request->requireName('x/x');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $newPackageB),
            array('job' => 'install', 'package' => $packageA),
            array('job' => 'install', 'package' => $packageX),
        ));
    }

    public function testInstallCircularRequire()
    {
        $this->repo->addPackage($packageA = $this->getPackage('a/a', '1.0'));
        $this->repo->addPackage($packageB1 = $this->getPackage('b/b', '0.9'));
        $this->repo->addPackage($packageB2 = $this->getPackage('b/b', '1.1'));
        $packageA->setRequires(array('b/b' => new Link('a/a', 'b/b', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE)));
        $packageB2->setRequires(array('a/a' => new Link('b/b', 'a/a', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE)));

        $this->reposComplete();

        $this->request->requireName('a/a');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageB2),
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testInstallAlternativeWithCircularRequire()
    {
        $this->repo->addPackage($packageA = $this->getPackage('a/a', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('b/b', '1.0'));
        $this->repo->addPackage($packageC = $this->getPackage('c/c', '1.0'));
        $this->repo->addPackage($packageD = $this->getPackage('d/d', '1.0'));
        $packageA->setRequires(array('b/b' => new Link('a/a', 'b/b', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE)));
        $packageB->setRequires(array('virtual/virtual' => new Link('b/b', 'virtual/virtual', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE)));
        $packageC->setProvides(array('virtual/virtual' => new Link('c/c', 'virtual/virtual', $this->getVersionConstraint('==', '1.0'), Link::TYPE_PROVIDE)));
        $packageD->setProvides(array('virtual/virtual' => new Link('d/d', 'virtual/virtual', $this->getVersionConstraint('==', '1.0'), Link::TYPE_PROVIDE)));

        $packageC->setRequires(array('a/a' => new Link('c/c', 'a/a', $this->getVersionConstraint('==', '1.0'), Link::TYPE_REQUIRE)));
        $packageD->setRequires(array('a/a' => new Link('d/d', 'a/a', $this->getVersionConstraint('==', '1.0'), Link::TYPE_REQUIRE)));

        $this->reposComplete();

        $this->request->requireName('a/a');
        $this->request->requireName('c/c');

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
        $this->repo->addPackage($packageA = $this->getPackage('a/a', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('b/b', '1.0'));
        $this->repo->addPackage($packageD = $this->getPackage('d/d', '1.0'));
        $this->repo->addPackage($packageD2 = $this->getPackage('d/d', '1.1'));

        $packageA->setRequires(array(
            'b/b' => new Link('a/a', 'b/b', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
            'c/c' => new Link('a/a', 'c/c', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
        ));

        $packageD->setReplaces(array(
            'b/b' => new Link('d/d', 'b/b', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REPLACE),
            'c/c' => new Link('d/d', 'c/c', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REPLACE),
        ));

        $packageD2->setReplaces(array(
            'b/b' => new Link('d/d', 'b/b', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REPLACE),
            'c/c' => new Link('d/d', 'c/c', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REPLACE),
        ));

        $this->reposComplete();

        $this->request->requireName('a/a');
        $this->request->requireName('d/d');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageD2),
            array('job' => 'install', 'package' => $packageA),
        ));
    }

    public function testIssue265()
    {
        $this->repo->addPackage($packageA1 = $this->getPackage('a/a', '2.0.999999-dev'));
        $this->repo->addPackage($packageA2 = $this->getPackage('a/a', '2.1-dev'));
        $this->repo->addPackage($packageA3 = $this->getPackage('a/a', '2.2-dev'));
        $this->repo->addPackage($packageB1 = $this->getPackage('b/b', '2.0.10'));
        $this->repo->addPackage($packageB2 = $this->getPackage('b/b', '2.0.9'));
        $this->repo->addPackage($packageC = $this->getPackage('c/c', '2.0-dev'));
        $this->repo->addPackage($packageD = $this->getPackage('d/d', '2.0.9'));

        $packageC->setRequires(array(
            'a/a' => new Link('c/c', 'a/a', $this->getVersionConstraint('>=', '2.0'), Link::TYPE_REQUIRE),
            'd/d' => new Link('c/c', 'd/d', $this->getVersionConstraint('>=', '2.0'), Link::TYPE_REQUIRE),
        ));

        $packageD->setRequires(array(
            'a/a' => new Link('d/d', 'a/a', $this->getVersionConstraint('>=', '2.1'), Link::TYPE_REQUIRE),
            'b/b' => new Link('d/d', 'b/b', $this->getVersionConstraint('>=', '2.0-dev'), Link::TYPE_REQUIRE),
        ));

        $packageB1->setRequires(array('a/a' => new Link('b/b', 'a/a', $this->getVersionConstraint('==', '2.1.0.0-dev'), Link::TYPE_REQUIRE)));
        $packageB2->setRequires(array('a/a' => new Link('b/b', 'a/a', $this->getVersionConstraint('==', '2.1.0.0-dev'), Link::TYPE_REQUIRE)));

        $packageB2->setReplaces(array('d/d' => new Link('b/b', 'd/d', $this->getVersionConstraint('==', '2.0.9.0'), Link::TYPE_REPLACE)));

        $this->reposComplete();

        $this->request->requireName('c/c', $this->getVersionConstraint('==', '2.0.0.0-dev'));

        $this->setExpectedException('Composer\DependencyResolver\SolverProblemsException');

        $this->createSolver();
        $this->solver->solve($this->request);
    }

    public function testConflictResultEmpty()
    {
        $this->repo->addPackage($packageA = $this->getPackage('a/a', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('b/b', '1.0'));
        $packageA->setConflicts(array(
            'b/b' => new Link('a/a', 'b/b', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_CONFLICT),
        ));

        $this->reposComplete();

        $emptyConstraint = new MatchAllConstraint();
        $emptyConstraint->setPrettyString('*');

        $this->request->requireName('a/a', $emptyConstraint);
        $this->request->requireName('b/b', $emptyConstraint);

        $this->createSolver();
        try {
            $transaction = $this->solver->solve($this->request);
            $this->fail('Unsolvable conflict did not result in exception.');
        } catch (SolverProblemsException $e) {
            $problems = $e->getProblems();
            $this->assertCount(1, $problems);

            $msg = "\n";
            $msg .= "  Problem 1\n";
            $msg .= "    - Root composer.json requires a/a * -> satisfiable by a/a[1.0].\n";
            $msg .= "    - a/a 1.0 conflicts with b/b 1.0.\n";
            $msg .= "    - Root composer.json requires b/b * -> satisfiable by b/b[1.0].\n";
            $this->assertEquals($msg, $e->getPrettyString($this->repoSet, $this->request, $this->pool, false));
        }
    }

    public function testUnsatisfiableRequires()
    {
        $this->repo->addPackage($packageA = $this->getPackage('a/a', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('b/b', '1.0'));

        $packageA->setRequires(array(
            'b/b' => new Link('a/a', 'b/b', $this->getVersionConstraint('>=', '2.0'), Link::TYPE_REQUIRE),
        ));

        $this->reposComplete();

        $this->request->requireName('a/a');

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
            $msg .= "    - Root composer.json requires a/a * -> satisfiable by a/a[1.0].\n";
            $msg .= "    - a/a 1.0 requires b/b >= 2.0 -> found b/b[1.0] but it does not match the constraint.\n";
            $this->assertEquals($msg, $e->getPrettyString($this->repoSet, $this->request, $this->pool, false));
        }
    }

    public function testRequireMismatchException()
    {
        $this->repo->addPackage($packageA = $this->getPackage('a/a', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('b/b', '1.0'));
        $this->repo->addPackage($packageB2 = $this->getPackage('b/b', '0.9'));
        $this->repo->addPackage($packageC = $this->getPackage('c/c', '1.0'));
        $this->repo->addPackage($packageD = $this->getPackage('d/d', '1.0'));

        $packageA->setRequires(array(
            'b/b' => new Link('a/a', 'b/b', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
        ));
        $packageB->setRequires(array(
            'c/c' => new Link('b/b', 'c/c', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
        ));
        $packageC->setRequires(array(
            'd/d' => new Link('c/c', 'd/d', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
        ));
        $packageD->setRequires(array(
            'b/b' => new Link('d/d', 'b/b', $this->getVersionConstraint('<', '1.0'), Link::TYPE_REQUIRE),
        ));

        $this->reposComplete();

        $emptyConstraint = new MatchAllConstraint();
        $emptyConstraint->setPrettyString('*');

        $this->request->requireName('a/a', $emptyConstraint);

        $this->createSolver();
        try {
            $transaction = $this->solver->solve($this->request);
            $this->fail('Unsolvable conflict did not result in exception.');
        } catch (SolverProblemsException $e) {
            $problems = $e->getProblems();
            $this->assertCount(1, $problems);

            $msg = "\n";
            $msg .= "  Problem 1\n";
            $msg .= "    - c/c 1.0 requires d/d >= 1.0 -> satisfiable by d/d[1.0].\n";
            $msg .= "    - d/d 1.0 requires b/b < 1.0 -> satisfiable by b/b[0.9].\n";
            $msg .= "    - b/b 1.0 requires c/c >= 1.0 -> satisfiable by c/c[1.0].\n";
            $msg .= "    - You can only install one version of a package, so only one of these can be installed: b/b[0.9, 1.0].\n";
            $msg .= "    - a/a 1.0 requires b/b >= 1.0 -> satisfiable by b/b[1.0].\n";
            $msg .= "    - Root composer.json requires a/a * -> satisfiable by a/a[1.0].\n";
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
        $this->repo->addPackage($packageA = $this->getPackage('a/a', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('b/b', '2.0'));
        $this->repo->addPackage($packageA2 = $this->getPackage('a/a', '2.0'));

        $packageA2->setRequires(array(
            'b/b' => new Link('a/a', 'b/b', $this->getVersionConstraint('==', '2.0'), Link::TYPE_REQUIRE, '== 2.0'),
        ));
        $packageB->setRequires(array(
            'a/a' => new Link('b/b', 'a/a', $this->getVersionConstraint('>=', '2.0'), Link::TYPE_REQUIRE),
        ));

        $this->repo->addPackage($packageA2Alias = $this->getAliasPackage($packageA2, '1.1'));

        $this->reposComplete();

        $this->request->requireName('a/a', $this->getVersionConstraint('==', '1.1.0.0'));

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageB),
            array('job' => 'install', 'package' => $packageA2),
            array('job' => 'markAliasInstalled', 'package' => $packageA2Alias),
        ));
    }

    public function testInstallDevAlias()
    {
        $this->repo->addPackage($packageA = $this->getPackage('a/a', '2.0'));
        $this->repo->addPackage($packageB = $this->getPackage('b/b', '1.0'));

        $packageB->setRequires(array(
            'a/a' => new Link('b/b', 'a/a', $this->getVersionConstraint('<', '2.0'), Link::TYPE_REQUIRE),
        ));

        $this->repo->addPackage($packageAAlias = $this->getAliasPackage($packageA, '1.1'));

        $this->reposComplete();

        $this->request->requireName('a/a', $this->getVersionConstraint('==', '2.0'));
        $this->request->requireName('b/b');

        $this->checkSolverResult(array(
            array('job' => 'install', 'package' => $packageA),
            array('job' => 'markAliasInstalled', 'package' => $packageAAlias),
            array('job' => 'install', 'package' => $packageB),
        ));
    }

    public function testInstallRootAliasesIfAliasOfIsInstalled()
    {
        // root aliased, required
        $this->repo->addPackage($packageA = $this->getPackage('a/a', '1.0'));
        $this->repo->addPackage($packageAAlias = $this->getAliasPackage($packageA, '1.1'));
        $packageAAlias->setRootPackageAlias(true);
        // root aliased, not required, should still be installed as it is root alias
        $this->repo->addPackage($packageB = $this->getPackage('b/b', '1.0'));
        $this->repo->addPackage($packageBAlias = $this->getAliasPackage($packageB, '1.1'));
        $packageBAlias->setRootPackageAlias(true);
        // regular alias, not required, alias should not be installed
        $this->repo->addPackage($packageC = $this->getPackage('c/c', '1.0'));
        $this->repo->addPackage($packageCAlias = $this->getAliasPackage($packageC, '1.1'));

        $this->reposComplete();

        $this->request->requireName('a/a', $this->getVersionConstraint('==', '1.1'));
        $this->request->requireName('b/b', $this->getVersionConstraint('==', '1.0'));
        $this->request->requireName('c/c', $this->getVersionConstraint('==', '1.0'));

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
        $this->repo->addPackage($packageA = $this->getPackage('a/a', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('b/b', '1.0'));
        $this->repo->addPackage($packageC1 = $this->getPackage('c/c', '1.0'));
        $this->repo->addPackage($packageC2 = $this->getPackage('c/c', '2.0'));
        $this->repo->addPackage($packageD = $this->getPackage('d/d', '1.0'));
        $this->repo->addPackage($packageE = $this->getPackage('e/e', '1.0'));
        $this->repo->addPackage($packageF1 = $this->getPackage('f/f', '1.0'));
        $this->repo->addPackage($packageF2 = $this->getPackage('f/f', '2.0'));
        $this->repo->addPackage($packageG1 = $this->getPackage('g/g', '1.0'));
        $this->repo->addPackage($packageG2 = $this->getPackage('g/g', '2.0'));
        $this->repo->addPackage($packageG3 = $this->getPackage('g/g', '3.0'));

        $packageA->setRequires(array(
            'b/b' => new Link('a/a', 'b/b', $this->getVersionConstraint('==', '1.0'), Link::TYPE_REQUIRE),
            'c/c' => new Link('a/a', 'c/c', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
            'd/d' => new Link('a/a', 'd/d', $this->getVersionConstraint('==', '1.0'), Link::TYPE_REQUIRE),
        ));

        $packageB->setRequires(array(
            'e/e' => new Link('b/b', 'e/e', $this->getVersionConstraint('==', '1.0'), Link::TYPE_REQUIRE),
        ));

        $packageC1->setRequires(array(
            'f/f' => new Link('c/c', 'f/f', $this->getVersionConstraint('==', '1.0'), Link::TYPE_REQUIRE),
        ));
        $packageC2->setRequires(array(
            'f/f' => new Link('c/c', 'f/f', $this->getVersionConstraint('==', '1.0'), Link::TYPE_REQUIRE),
            'g/g' => new Link('c/c', 'g/g', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
        ));

        $packageD->setRequires(array(
            'f/f' => new Link('d/d', 'f/f', $this->getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
        ));

        $packageE->setRequires(array(
            'g/g' => new Link('e/e', 'g/g', $this->getVersionConstraint('<=', '2.0'), Link::TYPE_REQUIRE),
        ));

        $this->reposComplete();

        $this->request->requireName('a/a');

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

    /**
     * @return void
     */
    protected function reposComplete()
    {
        $this->repoSet->addRepository($this->repo);
        $this->repoSet->addRepository($this->repoLocked);
    }

    /**
     * @return void
     */
    protected function createSolver()
    {
        $io = new NullIO();
        $this->pool = $this->repoSet->createPool($this->request, $io);
        $this->solver = new Solver($this->policy, $this->pool, $io);
    }

    /**
     * @param array<array<string, string>> $expected
     * @return void
     */
    protected function checkSolverResult(array $expected)
    {
        $this->createSolver();
        $transaction = $this->solver->solve($this->request);

        $result = array();
        foreach ($transaction->getOperations() as $operation) {
            if ($operation instanceof UpdateOperation) {
                $result[] = array(
                    'job' => 'update',
                    'from' => $operation->getInitialPackage(),
                    'to' => $operation->getTargetPackage(),
                );
            } elseif ($operation instanceof MarkAliasInstalledOperation || $operation instanceof MarkAliasUninstalledOperation) {
                $result[] = array(
                    'job' => $operation->getOperationType(),
                    'package' => $operation->getPackage(),
                );
            } elseif ($operation instanceof UninstallOperation || $operation instanceof InstallOperation) {
                $job = ('uninstall' === $operation->getOperationType() ? 'remove' : 'install');
                $result[] = array(
                    'job' => $job,
                    'package' => $operation->getPackage(),
                );
            } else {
                throw new \LogicException('Unexpected operation: '.get_class($operation));
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
