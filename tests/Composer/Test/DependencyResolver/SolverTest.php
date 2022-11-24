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
use Composer\Package\PackageInterface;
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

    public function setUp(): void
    {
        $this->repoSet = new RepositorySet();
        $this->repo = new ArrayRepository;
        $this->repoLocked = new LockArrayRepository;

        $this->request = new Request($this->repoLocked);
        $this->policy = new DefaultPolicy;
    }

    public function testSolverInstallSingle(): void
    {
        $this->repo->addPackage($packageA = self::getPackage('A', '1.0'));
        $this->reposComplete();

        $this->request->requireName('A');

        $this->checkSolverResult([
            ['job' => 'install', 'package' => $packageA],
        ]);
    }

    public function testSolverRemoveIfNotRequested(): void
    {
        $this->repoLocked->addPackage($packageA = self::getPackage('A', '1.0'));
        $this->reposComplete();

        $this->checkSolverResult([
            ['job' => 'remove', 'package' => $packageA],
        ]);
    }

    public function testInstallNonExistingPackageFails(): void
    {
        $this->repo->addPackage(self::getPackage('A', '1.0'));
        $this->reposComplete();

        $this->request->requireName('B', self::getVersionConstraint('==', '1'));

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

    public function testSolverInstallSamePackageFromDifferentRepositories(): void
    {
        $repo1 = new ArrayRepository;
        $repo2 = new ArrayRepository;

        $repo1->addPackage($foo1 = self::getPackage('foo', '1'));
        $repo2->addPackage($foo2 = self::getPackage('foo', '1'));

        $this->repoSet->addRepository($repo1);
        $this->repoSet->addRepository($repo2);

        $this->request->requireName('foo');

        $this->checkSolverResult([
                ['job' => 'install', 'package' => $foo1],
        ]);
    }

    public function testSolverInstallWithDeps(): void
    {
        $this->repo->addPackage($packageA = self::getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = self::getPackage('B', '1.0'));
        $this->repo->addPackage($newPackageB = self::getPackage('B', '1.1'));

        $packageA->setRequires(['b' => new Link('A', 'B', self::getVersionConstraint('<', '1.1'), Link::TYPE_REQUIRE)]);

        $this->reposComplete();

        $this->request->requireName('A');

        $this->checkSolverResult([
            ['job' => 'install', 'package' => $packageB],
            ['job' => 'install', 'package' => $packageA],
        ]);
    }

    public function testSolverInstallHonoursNotEqualOperator(): void
    {
        $this->repo->addPackage($packageA = self::getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = self::getPackage('B', '1.0'));
        $this->repo->addPackage($newPackageB11 = self::getPackage('B', '1.1'));
        $this->repo->addPackage($newPackageB12 = self::getPackage('B', '1.2'));
        $this->repo->addPackage($newPackageB13 = self::getPackage('B', '1.3'));

        $packageA->setRequires([
            'b' => new Link('A', 'B', new MultiConstraint([
                self::getVersionConstraint('<=', '1.3'),
                self::getVersionConstraint('<>', '1.3'),
                self::getVersionConstraint('!=', '1.2'),
            ]), Link::TYPE_REQUIRE),
        ]);

        $this->reposComplete();

        $this->request->requireName('A');

        $this->checkSolverResult([
            ['job' => 'install', 'package' => $newPackageB11],
            ['job' => 'install', 'package' => $packageA],
        ]);
    }

    public function testSolverInstallWithDepsInOrder(): void
    {
        $this->repo->addPackage($packageA = self::getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = self::getPackage('B', '1.0'));
        $this->repo->addPackage($packageC = self::getPackage('C', '1.0'));

        $packageB->setRequires([
            'a' => new Link('B', 'A', self::getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
            'c' => new Link('B', 'C', self::getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
        ]);
        $packageC->setRequires([
            'a' => new Link('C', 'A', self::getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
        ]);

        $this->reposComplete();

        $this->request->requireName('A');
        $this->request->requireName('B');
        $this->request->requireName('C');

        $this->checkSolverResult([
            ['job' => 'install', 'package' => $packageA],
            ['job' => 'install', 'package' => $packageC],
            ['job' => 'install', 'package' => $packageB],
        ]);
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
    public function testSolverMultiPackageNameVersionResolutionDependsOnRequireOrder(): void
    {
        $this->repo->addPackage($php74 = self::getPackage('ourcustom/PHP', '7.4.23'));
        $this->repo->addPackage($php80 = self::getPackage('ourcustom/PHP', '8.0.10'));
        $this->repo->addPackage($extForPhp74 = self::getPackage('ourcustom/ext-foobar', '1.0'));
        $this->repo->addPackage($extForPhp80 = self::getPackage('ourcustom/ext-foobar', '1.0'));

        $extForPhp74->setRequires([
            'ourcustom/php' => new Link('ourcustom/ext-foobar', 'ourcustom/PHP', new MultiConstraint([
                self::getVersionConstraint('>=', '7.4.0'),
                self::getVersionConstraint('<', '7.5.0'),
            ]), Link::TYPE_REQUIRE),
        ]);
        $extForPhp80->setRequires([
            'ourcustom/php' => new Link('ourcustom/ext-foobar', 'ourcustom/PHP', new MultiConstraint([
                self::getVersionConstraint('>=', '8.0.0'),
                self::getVersionConstraint('<', '8.1.0'),
            ]), Link::TYPE_REQUIRE),
        ]);

        $this->reposComplete();

        $this->request->requireName('ourcustom/PHP');
        $this->request->requireName('ourcustom/ext-foobar');

        $this->checkSolverResult([
            ['job' => 'install', 'package' => $php80],
            ['job' => 'install', 'package' => $extForPhp80],
        ]);

        // now we flip the requirements around: we request "ext-foobar" before "php"
        // because the ext-foobar package that requires php74 comes first in the repo, and the one that requires php80 second, the solver will pick the one for php74, and then, as it is a dependency, also php74
        // this is because both packages have the same name and version; just their requirements differ
        // and because no other constraint forces a particular version of package "php"
        $this->request = new Request($this->repoLocked);
        $this->request->requireName('ourcustom/ext-foobar');
        $this->request->requireName('ourcustom/PHP');

        $this->checkSolverResult([
            ['job' => 'install', 'package' => $php74],
            ['job' => 'install', 'package' => $extForPhp74],
        ]);
    }

    /**
     * This test is almost the same as above, except we're inserting the package with the requirement on the other package in a different order, asserting that if that is done, the order of requirements no longer matters
     *
     * CAUTION: IF THIS TEST EVER FAILS, SOLVER BEHAVIOR HAS CHANGED AND MAY BREAK DOWNSTREAM USERS
     */
    public function testSolverMultiPackageNameVersionResolutionIsIndependentOfRequireOrderIfOrderedDescendingByRequirement(): void
    {
        $this->repo->addPackage($php74 = self::getPackage('ourcustom/PHP', '7.4'));
        $this->repo->addPackage($php80 = self::getPackage('ourcustom/PHP', '8.0'));
        $this->repo->addPackage($extForPhp80 = self::getPackage('ourcustom/ext-foobar', '1.0')); // note we are inserting this one into the repo first, unlike in the previous test
        $this->repo->addPackage($extForPhp74 = self::getPackage('ourcustom/ext-foobar', '1.0'));

        $extForPhp80->setRequires([
            'ourcustom/php' => new Link('ourcustom/ext-foobar', 'ourcustom/PHP', new MultiConstraint([
                self::getVersionConstraint('>=', '8.0.0'),
                self::getVersionConstraint('<', '8.1.0'),
            ]), Link::TYPE_REQUIRE),
        ]);
        $extForPhp74->setRequires([
            'ourcustom/php' => new Link('ourcustom/ext-foobar', 'ourcustom/PHP', new MultiConstraint([
                self::getVersionConstraint('>=', '7.4.0'),
                self::getVersionConstraint('<', '7.5.0'),
            ]), Link::TYPE_REQUIRE),
        ]);

        $this->reposComplete();

        $this->request->requireName('ourcustom/PHP');
        $this->request->requireName('ourcustom/ext-foobar');

        $this->checkSolverResult([
            ['job' => 'install', 'package' => $php80],
            ['job' => 'install', 'package' => $extForPhp80],
        ]);

        // unlike in the previous test, the order of requirements no longer matters now
        $this->request = new Request($this->repoLocked);
        $this->request->requireName('ourcustom/ext-foobar');
        $this->request->requireName('ourcustom/PHP');

        $this->checkSolverResult([
            ['job' => 'install', 'package' => $php80],
            ['job' => 'install', 'package' => $extForPhp80],
        ]);
    }

    public function testSolverFixLocked(): void
    {
        $this->repoLocked->addPackage($packageA = self::getPackage('A', '1.0'));
        $this->reposComplete();

        $this->request->fixPackage($packageA);

        $this->checkSolverResult([]);
    }

    public function testSolverFixLockedWithAlternative(): void
    {
        $this->repo->addPackage(self::getPackage('A', '1.0'));
        $this->repoLocked->addPackage($packageA = self::getPackage('A', '1.0'));
        $this->reposComplete();

        $this->request->fixPackage($packageA);

        $this->checkSolverResult([]);
    }

    public function testSolverUpdateDoesOnlyUpdate(): void
    {
        $this->repoLocked->addPackage($packageA = self::getPackage('A', '1.0'));
        $this->repoLocked->addPackage($packageB = self::getPackage('B', '1.0'));
        $this->repo->addPackage($newPackageB = self::getPackage('B', '1.1'));
        $this->reposComplete();

        $packageA->setRequires(['b' => new Link('A', 'B', self::getVersionConstraint('>=', '1.0.0.0'), Link::TYPE_REQUIRE)]);

        $this->request->fixPackage($packageA);
        $this->request->requireName('B', self::getVersionConstraint('=', '1.1.0.0'));

        $this->checkSolverResult([
            ['job' => 'update', 'from' => $packageB, 'to' => $newPackageB],
        ]);
    }

    public function testSolverUpdateSingle(): void
    {
        $this->repoLocked->addPackage($packageA = self::getPackage('A', '1.0'));
        $this->repo->addPackage($newPackageA = self::getPackage('A', '1.1'));
        $this->reposComplete();

        $this->request->requireName('A');

        $this->checkSolverResult([
            ['job' => 'update', 'from' => $packageA, 'to' => $newPackageA],
        ]);
    }

    public function testSolverUpdateAll(): void
    {
        $this->repoLocked->addPackage($packageA = self::getPackage('A', '1.0'));
        $this->repoLocked->addPackage($packageB = self::getPackage('B', '1.0'));
        $this->repo->addPackage($newPackageA = self::getPackage('A', '1.1'));
        $this->repo->addPackage($newPackageB = self::getPackage('B', '1.1'));

        $packageA->setRequires(['b' => new Link('A', 'B', new MatchAllConstraint(), Link::TYPE_REQUIRE)]);
        $newPackageA->setRequires(['b' => new Link('A', 'B', new MatchAllConstraint(), Link::TYPE_REQUIRE)]);

        $this->reposComplete();

        $this->request->requireName('A');

        $this->checkSolverResult([
            ['job' => 'update', 'from' => $packageB, 'to' => $newPackageB],
            ['job' => 'update', 'from' => $packageA, 'to' => $newPackageA],
        ]);
    }

    public function testSolverUpdateCurrent(): void
    {
        $this->repoLocked->addPackage(self::getPackage('A', '1.0'));
        $this->repo->addPackage(self::getPackage('A', '1.0'));
        $this->reposComplete();

        $this->request->requireName('A');

        $this->checkSolverResult([]);
    }

    public function testSolverUpdateOnlyUpdatesSelectedPackage(): void
    {
        $this->repoLocked->addPackage($packageA = self::getPackage('A', '1.0'));
        $this->repoLocked->addPackage($packageB = self::getPackage('B', '1.0'));
        $this->repo->addPackage($packageAnewer = self::getPackage('A', '1.1'));
        $this->repo->addPackage($packageBnewer = self::getPackage('B', '1.1'));

        $this->reposComplete();

        $this->request->requireName('A');
        $this->request->fixPackage($packageB);

        $this->checkSolverResult([
            ['job' => 'update', 'from' => $packageA, 'to' => $packageAnewer],
        ]);
    }

    public function testSolverUpdateConstrained(): void
    {
        $this->repoLocked->addPackage($packageA = self::getPackage('A', '1.0'));
        $this->repo->addPackage($newPackageA = self::getPackage('A', '1.2'));
        $this->repo->addPackage(self::getPackage('A', '2.0'));
        $this->reposComplete();

        $this->request->requireName('A', self::getVersionConstraint('<', '2.0.0.0'));

        $this->checkSolverResult([[
            'job' => 'update',
            'from' => $packageA,
            'to' => $newPackageA,
        ]]);
    }

    public function testSolverUpdateFullyConstrained(): void
    {
        $this->repoLocked->addPackage($packageA = self::getPackage('A', '1.0'));
        $this->repo->addPackage($newPackageA = self::getPackage('A', '1.2'));
        $this->repo->addPackage(self::getPackage('A', '2.0'));
        $this->reposComplete();

        $this->request->requireName('A', self::getVersionConstraint('<', '2.0.0.0'));

        $this->checkSolverResult([[
            'job' => 'update',
            'from' => $packageA,
            'to' => $newPackageA,
        ]]);
    }

    public function testSolverUpdateFullyConstrainedPrunesInstalledPackages(): void
    {
        $this->repoLocked->addPackage($packageA = self::getPackage('A', '1.0'));
        $this->repoLocked->addPackage($packageB = self::getPackage('B', '1.0'));
        $this->repo->addPackage($newPackageA = self::getPackage('A', '1.2'));
        $this->repo->addPackage(self::getPackage('A', '2.0'));
        $this->reposComplete();

        $this->request->requireName('A', self::getVersionConstraint('<', '2.0.0.0'));

        $this->checkSolverResult([
            [
                'job' => 'remove',
                'package' => $packageB,
            ],
            [
                'job' => 'update',
                'from' => $packageA,
                'to' => $newPackageA,
            ],
        ]);
    }

    public function testSolverAllJobs(): void
    {
        $this->repoLocked->addPackage($packageD = self::getPackage('D', '1.0'));
        $this->repoLocked->addPackage($oldPackageC = self::getPackage('C', '1.0'));

        $this->repo->addPackage($packageA = self::getPackage('A', '2.0'));
        $this->repo->addPackage($packageB = self::getPackage('B', '1.0'));
        $this->repo->addPackage($newPackageB = self::getPackage('B', '1.1'));
        $this->repo->addPackage($packageC = self::getPackage('C', '1.1'));
        $this->repo->addPackage(self::getPackage('D', '1.0'));
        $packageA->setRequires(['b' => new Link('A', 'B', self::getVersionConstraint('<', '1.1'), Link::TYPE_REQUIRE)]);

        $this->reposComplete();

        $this->request->requireName('A');
        $this->request->requireName('C');

        $this->checkSolverResult([
            ['job' => 'remove',  'package' => $packageD],
            ['job' => 'install', 'package' => $packageB],
            ['job' => 'install', 'package' => $packageA],
            ['job' => 'update',  'from' => $oldPackageC, 'to' => $packageC],
        ]);
    }

    public function testSolverThreeAlternativeRequireAndConflict(): void
    {
        $this->repo->addPackage($packageA = self::getPackage('A', '2.0'));
        $this->repo->addPackage($middlePackageB = self::getPackage('B', '1.0'));
        $this->repo->addPackage($newPackageB = self::getPackage('B', '1.1'));
        $this->repo->addPackage($oldPackageB = self::getPackage('B', '0.9'));
        $packageA->setRequires(['b' => new Link('A', 'B', self::getVersionConstraint('<', '1.1'), Link::TYPE_REQUIRE)]);
        $packageA->setConflicts(['b' => new Link('A', 'B', self::getVersionConstraint('<', '1.0'), Link::TYPE_CONFLICT)]);

        $this->reposComplete();

        $this->request->requireName('A');

        $this->checkSolverResult([
            ['job' => 'install', 'package' => $middlePackageB],
            ['job' => 'install', 'package' => $packageA],
        ]);
    }

    public function testSolverObsolete(): void
    {
        $this->repoLocked->addPackage($packageA = self::getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = self::getPackage('B', '1.0'));
        $packageB->setReplaces(['a' => new Link('B', 'A', new MatchAllConstraint())]);

        $this->reposComplete();

        $this->request->requireName('B');

        $this->checkSolverResult([
            ['job' => 'remove', 'package' => $packageA],
            ['job' => 'install', 'package' => $packageB],
        ]);
    }

    public function testInstallOneOfTwoAlternatives(): void
    {
        $this->repo->addPackage($packageA = self::getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = self::getPackage('A', '1.0'));

        $this->reposComplete();

        $this->request->requireName('A');

        $this->checkSolverResult([
            ['job' => 'install', 'package' => $packageA],
        ]);
    }

    public function testInstallProvider(): void
    {
        $this->repo->addPackage($packageA = self::getPackage('A', '1.0'));
        $this->repo->addPackage($packageQ = self::getPackage('Q', '1.0'));
        $packageA->setRequires(['b' => new Link('A', 'B', self::getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE)]);
        $packageQ->setProvides(['b' => new Link('Q', 'B', self::getVersionConstraint('=', '1.0'), Link::TYPE_PROVIDE)]);

        $this->reposComplete();

        $this->request->requireName('A');

        // must explicitly pick the provider, so error in this case
        self::expectException('Composer\DependencyResolver\SolverProblemsException');
        $this->createSolver();
        $this->solver->solve($this->request);
    }

    public function testSkipReplacerOfExistingPackage(): void
    {
        $this->repo->addPackage($packageA = self::getPackage('A', '1.0'));
        $this->repo->addPackage($packageQ = self::getPackage('Q', '1.0'));
        $this->repo->addPackage($packageB = self::getPackage('B', '1.0'));
        $packageA->setRequires(['b' => new Link('A', 'B', self::getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE)]);
        $packageQ->setReplaces(['b' => new Link('Q', 'B', self::getVersionConstraint('>=', '1.0'), Link::TYPE_REPLACE)]);

        $this->reposComplete();

        $this->request->requireName('A');

        $this->checkSolverResult([
            ['job' => 'install', 'package' => $packageB],
            ['job' => 'install', 'package' => $packageA],
        ]);
    }

    public function testNoInstallReplacerOfMissingPackage(): void
    {
        $this->repo->addPackage($packageA = self::getPackage('A', '1.0'));
        $this->repo->addPackage($packageQ = self::getPackage('Q', '1.0'));
        $packageA->setRequires(['b' => new Link('A', 'B', self::getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE)]);
        $packageQ->setReplaces(['b' => new Link('Q', 'B', self::getVersionConstraint('>=', '1.0'), Link::TYPE_REPLACE)]);

        $this->reposComplete();

        $this->request->requireName('A');

        self::expectException('Composer\DependencyResolver\SolverProblemsException');
        $this->createSolver();
        $this->solver->solve($this->request);
    }

    public function testSkipReplacedPackageIfReplacerIsSelected(): void
    {
        $this->repo->addPackage($packageA = self::getPackage('A', '1.0'));
        $this->repo->addPackage($packageQ = self::getPackage('Q', '1.0'));
        $this->repo->addPackage($packageB = self::getPackage('B', '1.0'));
        $packageA->setRequires(['b' => new Link('A', 'B', self::getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE)]);
        $packageQ->setReplaces(['b' => new Link('Q', 'B', self::getVersionConstraint('>=', '1.0'), Link::TYPE_REPLACE)]);

        $this->reposComplete();

        $this->request->requireName('A');
        $this->request->requireName('Q');

        $this->checkSolverResult([
            ['job' => 'install', 'package' => $packageQ],
            ['job' => 'install', 'package' => $packageA],
        ]);
    }

    public function testPickOlderIfNewerConflicts(): void
    {
        $this->repo->addPackage($packageX = self::getPackage('X', '1.0'));
        $packageX->setRequires([
            'a' => new Link('X', 'A', self::getVersionConstraint('>=', '2.0.0.0'), Link::TYPE_REQUIRE),
            'b' => new Link('X', 'B', self::getVersionConstraint('>=', '2.0.0.0'), Link::TYPE_REQUIRE),
        ]);

        $this->repo->addPackage($packageA = self::getPackage('A', '2.0.0'));
        $this->repo->addPackage($newPackageA = self::getPackage('A', '2.1.0'));
        $this->repo->addPackage($newPackageB = self::getPackage('B', '2.1.0'));

        $packageA->setRequires(['b' => new Link('A', 'B', self::getVersionConstraint('>=', '2.0.0.0'), Link::TYPE_REQUIRE)]);

        // new package A depends on version of package B that does not exist
        // => new package A is not installable
        $newPackageA->setRequires(['b' => new Link('A', 'B', self::getVersionConstraint('>=', '2.2.0.0'), Link::TYPE_REQUIRE)]);

        // add a package S replacing both A and B, so that S and B or S and A cannot be simultaneously installed
        // but an alternative option for A and B both exists
        // this creates a more difficult so solve conflict
        $this->repo->addPackage($packageS = self::getPackage('S', '2.0.0'));
        $packageS->setReplaces([
            'a' => new Link('S', 'A', self::getVersionConstraint('>=', '2.0.0.0'), Link::TYPE_REPLACE),
            'b' => new Link('S', 'B', self::getVersionConstraint('>=', '2.0.0.0'), Link::TYPE_REPLACE),
        ]);

        $this->reposComplete();

        $this->request->requireName('X');

        $this->checkSolverResult([
            ['job' => 'install', 'package' => $newPackageB],
            ['job' => 'install', 'package' => $packageA],
            ['job' => 'install', 'package' => $packageX],
        ]);
    }

    public function testInstallCircularRequire(): void
    {
        $this->repo->addPackage($packageA = self::getPackage('A', '1.0'));
        $this->repo->addPackage($packageB1 = self::getPackage('B', '0.9'));
        $this->repo->addPackage($packageB2 = self::getPackage('B', '1.1'));
        $packageA->setRequires(['b' => new Link('A', 'B', self::getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE)]);
        $packageB2->setRequires(['a' => new Link('B', 'A', self::getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE)]);

        $this->reposComplete();

        $this->request->requireName('A');

        $this->checkSolverResult([
            ['job' => 'install', 'package' => $packageB2],
            ['job' => 'install', 'package' => $packageA],
        ]);
    }

    public function testInstallAlternativeWithCircularRequire(): void
    {
        $this->repo->addPackage($packageA = self::getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = self::getPackage('B', '1.0'));
        $this->repo->addPackage($packageC = self::getPackage('C', '1.0'));
        $this->repo->addPackage($packageD = self::getPackage('D', '1.0'));
        $packageA->setRequires(['b' => new Link('A', 'B', self::getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE)]);
        $packageB->setRequires(['virtual' => new Link('B', 'Virtual', self::getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE)]);
        $packageC->setProvides(['virtual' => new Link('C', 'Virtual', self::getVersionConstraint('==', '1.0'), Link::TYPE_PROVIDE)]);
        $packageD->setProvides(['virtual' => new Link('D', 'Virtual', self::getVersionConstraint('==', '1.0'), Link::TYPE_PROVIDE)]);

        $packageC->setRequires(['a' => new Link('C', 'A', self::getVersionConstraint('==', '1.0'), Link::TYPE_REQUIRE)]);
        $packageD->setRequires(['a' => new Link('D', 'A', self::getVersionConstraint('==', '1.0'), Link::TYPE_REQUIRE)]);

        $this->reposComplete();

        $this->request->requireName('A');
        $this->request->requireName('C');

        $this->checkSolverResult([
            ['job' => 'install', 'package' => $packageB],
            ['job' => 'install', 'package' => $packageA],
            ['job' => 'install', 'package' => $packageC],
        ]);
    }

    /**
     * If a replacer D replaces B and C with C not otherwise available,
     * D must be installed instead of the original B.
     */
    public function testUseReplacerIfNecessary(): void
    {
        $this->repo->addPackage($packageA = self::getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = self::getPackage('B', '1.0'));
        $this->repo->addPackage($packageD = self::getPackage('D', '1.0'));
        $this->repo->addPackage($packageD2 = self::getPackage('D', '1.1'));

        $packageA->setRequires([
            'b' => new Link('A', 'B', self::getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
            'c' => new Link('A', 'C', self::getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
        ]);

        $packageD->setReplaces([
            'b' => new Link('D', 'B', self::getVersionConstraint('>=', '1.0'), Link::TYPE_REPLACE),
            'c' => new Link('D', 'C', self::getVersionConstraint('>=', '1.0'), Link::TYPE_REPLACE),
        ]);

        $packageD2->setReplaces([
            'b' => new Link('D', 'B', self::getVersionConstraint('>=', '1.0'), Link::TYPE_REPLACE),
            'c' => new Link('D', 'C', self::getVersionConstraint('>=', '1.0'), Link::TYPE_REPLACE),
        ]);

        $this->reposComplete();

        $this->request->requireName('A');
        $this->request->requireName('D');

        $this->checkSolverResult([
            ['job' => 'install', 'package' => $packageD2],
            ['job' => 'install', 'package' => $packageA],
        ]);
    }

    public function testIssue265(): void
    {
        $this->repo->addPackage($packageA1 = self::getPackage('A', '2.0.999999-dev'));
        $this->repo->addPackage($packageA2 = self::getPackage('A', '2.1-dev'));
        $this->repo->addPackage($packageA3 = self::getPackage('A', '2.2-dev'));
        $this->repo->addPackage($packageB1 = self::getPackage('B', '2.0.10'));
        $this->repo->addPackage($packageB2 = self::getPackage('B', '2.0.9'));
        $this->repo->addPackage($packageC = self::getPackage('C', '2.0-dev'));
        $this->repo->addPackage($packageD = self::getPackage('D', '2.0.9'));

        $packageC->setRequires([
            'a' => new Link('C', 'A', self::getVersionConstraint('>=', '2.0'), Link::TYPE_REQUIRE),
            'd' => new Link('C', 'D', self::getVersionConstraint('>=', '2.0'), Link::TYPE_REQUIRE),
        ]);

        $packageD->setRequires([
            'a' => new Link('D', 'A', self::getVersionConstraint('>=', '2.1'), Link::TYPE_REQUIRE),
            'b' => new Link('D', 'B', self::getVersionConstraint('>=', '2.0-dev'), Link::TYPE_REQUIRE),
        ]);

        $packageB1->setRequires(['a' => new Link('B', 'A', self::getVersionConstraint('==', '2.1.0.0-dev'), Link::TYPE_REQUIRE)]);
        $packageB2->setRequires(['a' => new Link('B', 'A', self::getVersionConstraint('==', '2.1.0.0-dev'), Link::TYPE_REQUIRE)]);

        $packageB2->setReplaces(['d' => new Link('B', 'D', self::getVersionConstraint('==', '2.0.9.0'), Link::TYPE_REPLACE)]);

        $this->reposComplete();

        $this->request->requireName('C', self::getVersionConstraint('==', '2.0.0.0-dev'));

        self::expectException('Composer\DependencyResolver\SolverProblemsException');

        $this->createSolver();
        $this->solver->solve($this->request);
    }

    public function testConflictResultEmpty(): void
    {
        $this->repo->addPackage($packageA = self::getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = self::getPackage('B', '1.0'));
        $packageA->setConflicts([
            'b' => new Link('A', 'B', self::getVersionConstraint('>=', '1.0'), Link::TYPE_CONFLICT),
        ]);

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

    public function testUnsatisfiableRequires(): void
    {
        $this->repo->addPackage($packageA = self::getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = self::getPackage('B', '1.0'));

        $packageA->setRequires([
            'b' => new Link('A', 'B', self::getVersionConstraint('>=', '2.0'), Link::TYPE_REQUIRE),
        ]);

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

    public function testRequireMismatchException(): void
    {
        $this->repo->addPackage($packageA = self::getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = self::getPackage('B', '1.0'));
        $this->repo->addPackage($packageB2 = self::getPackage('B', '0.9'));
        $this->repo->addPackage($packageC = self::getPackage('C', '1.0'));
        $this->repo->addPackage($packageD = self::getPackage('D', '1.0'));

        $packageA->setRequires([
            'b' => new Link('A', 'B', self::getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
        ]);
        $packageB->setRequires([
            'c' => new Link('B', 'C', self::getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
        ]);
        $packageC->setRequires([
            'd' => new Link('C', 'D', self::getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
        ]);
        $packageD->setRequires([
            'b' => new Link('D', 'B', self::getVersionConstraint('<', '1.0'), Link::TYPE_REQUIRE),
        ]);

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

    public function testLearnLiteralsWithSortedRuleLiterals(): void
    {
        $this->repo->addPackage($packageTwig2 = self::getPackage('twig/twig', '2.0'));
        $this->repo->addPackage($packageTwig16 = self::getPackage('twig/twig', '1.6'));
        $this->repo->addPackage($packageTwig15 = self::getPackage('twig/twig', '1.5'));
        $this->repo->addPackage($packageSymfony = self::getPackage('symfony/symfony', '2.0'));
        $this->repo->addPackage($packageTwigBridge = self::getPackage('symfony/twig-bridge', '2.0'));

        $packageTwigBridge->setRequires([
            'twig/twig' => new Link('symfony/twig-bridge', 'twig/twig', self::getVersionConstraint('<', '2.0'), Link::TYPE_REQUIRE),
        ]);

        $packageSymfony->setReplaces([
            'symfony/twig-bridge' => new Link('symfony/symfony', 'symfony/twig-bridge', self::getVersionConstraint('==', '2.0'), Link::TYPE_REPLACE),
        ]);

        $this->reposComplete();

        $this->request->requireName('symfony/twig-bridge');
        $this->request->requireName('twig/twig');

        $this->checkSolverResult([
            ['job' => 'install', 'package' => $packageTwig16],
            ['job' => 'install', 'package' => $packageTwigBridge],
        ]);
    }

    public function testInstallRecursiveAliasDependencies(): void
    {
        $this->repo->addPackage($packageA = self::getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = self::getPackage('B', '2.0'));
        $this->repo->addPackage($packageA2 = self::getPackage('A', '2.0'));

        $packageA2->setRequires([
            'b' => new Link('A', 'B', self::getVersionConstraint('==', '2.0'), Link::TYPE_REQUIRE, '== 2.0'),
        ]);
        $packageB->setRequires([
            'a' => new Link('B', 'A', self::getVersionConstraint('>=', '2.0'), Link::TYPE_REQUIRE),
        ]);

        $this->repo->addPackage($packageA2Alias = self::getAliasPackage($packageA2, '1.1'));

        $this->reposComplete();

        $this->request->requireName('A', self::getVersionConstraint('==', '1.1.0.0'));

        $this->checkSolverResult([
            ['job' => 'install', 'package' => $packageB],
            ['job' => 'install', 'package' => $packageA2],
            ['job' => 'markAliasInstalled', 'package' => $packageA2Alias],
        ]);
    }

    public function testInstallDevAlias(): void
    {
        $this->repo->addPackage($packageA = self::getPackage('A', '2.0'));
        $this->repo->addPackage($packageB = self::getPackage('B', '1.0'));

        $packageB->setRequires([
            'a' => new Link('B', 'A', self::getVersionConstraint('<', '2.0'), Link::TYPE_REQUIRE),
        ]);

        $this->repo->addPackage($packageAAlias = self::getAliasPackage($packageA, '1.1'));

        $this->reposComplete();

        $this->request->requireName('A', self::getVersionConstraint('==', '2.0'));
        $this->request->requireName('B');

        $this->checkSolverResult([
            ['job' => 'install', 'package' => $packageA],
            ['job' => 'markAliasInstalled', 'package' => $packageAAlias],
            ['job' => 'install', 'package' => $packageB],
        ]);
    }

    public function testInstallRootAliasesIfAliasOfIsInstalled(): void
    {
        // root aliased, required
        $this->repo->addPackage($packageA = self::getPackage('A', '1.0'));
        $this->repo->addPackage($packageAAlias = self::getAliasPackage($packageA, '1.1'));
        $packageAAlias->setRootPackageAlias(true);
        // root aliased, not required, should still be installed as it is root alias
        $this->repo->addPackage($packageB = self::getPackage('B', '1.0'));
        $this->repo->addPackage($packageBAlias = self::getAliasPackage($packageB, '1.1'));
        $packageBAlias->setRootPackageAlias(true);
        // regular alias, not required, alias should not be installed
        $this->repo->addPackage($packageC = self::getPackage('C', '1.0'));
        $this->repo->addPackage($packageCAlias = self::getAliasPackage($packageC, '1.1'));

        $this->reposComplete();

        $this->request->requireName('A', self::getVersionConstraint('==', '1.1'));
        $this->request->requireName('B', self::getVersionConstraint('==', '1.0'));
        $this->request->requireName('C', self::getVersionConstraint('==', '1.0'));

        $this->checkSolverResult([
            ['job' => 'install', 'package' => $packageA],
            ['job' => 'markAliasInstalled', 'package' => $packageAAlias],
            ['job' => 'install', 'package' => $packageB],
            ['job' => 'markAliasInstalled', 'package' => $packageBAlias],
            ['job' => 'install', 'package' => $packageC],
            ['job' => 'markAliasInstalled', 'package' => $packageCAlias],
        ]);
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
    public function testLearnPositiveLiteral(): void
    {
        $this->repo->addPackage($packageA = self::getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = self::getPackage('B', '1.0'));
        $this->repo->addPackage($packageC1 = self::getPackage('C', '1.0'));
        $this->repo->addPackage($packageC2 = self::getPackage('C', '2.0'));
        $this->repo->addPackage($packageD = self::getPackage('D', '1.0'));
        $this->repo->addPackage($packageE = self::getPackage('E', '1.0'));
        $this->repo->addPackage($packageF1 = self::getPackage('F', '1.0'));
        $this->repo->addPackage($packageF2 = self::getPackage('F', '2.0'));
        $this->repo->addPackage($packageG1 = self::getPackage('G', '1.0'));
        $this->repo->addPackage($packageG2 = self::getPackage('G', '2.0'));
        $this->repo->addPackage($packageG3 = self::getPackage('G', '3.0'));

        $packageA->setRequires([
            'b' => new Link('A', 'B', self::getVersionConstraint('==', '1.0'), Link::TYPE_REQUIRE),
            'c' => new Link('A', 'C', self::getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
            'd' => new Link('A', 'D', self::getVersionConstraint('==', '1.0'), Link::TYPE_REQUIRE),
        ]);

        $packageB->setRequires([
            'e' => new Link('B', 'E', self::getVersionConstraint('==', '1.0'), Link::TYPE_REQUIRE),
        ]);

        $packageC1->setRequires([
            'f' => new Link('C', 'F', self::getVersionConstraint('==', '1.0'), Link::TYPE_REQUIRE),
        ]);
        $packageC2->setRequires([
            'f' => new Link('C', 'F', self::getVersionConstraint('==', '1.0'), Link::TYPE_REQUIRE),
            'g' => new Link('C', 'G', self::getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
        ]);

        $packageD->setRequires([
            'f' => new Link('D', 'F', self::getVersionConstraint('>=', '1.0'), Link::TYPE_REQUIRE),
        ]);

        $packageE->setRequires([
            'g' => new Link('E', 'G', self::getVersionConstraint('<=', '2.0'), Link::TYPE_REQUIRE),
        ]);

        $this->reposComplete();

        $this->request->requireName('A');

        $this->createSolver();

        // check correct setup for assertion later
        $this->assertFalse($this->solver->testFlagLearnedPositiveLiteral);

        $this->checkSolverResult([
            ['job' => 'install', 'package' => $packageF1],
            ['job' => 'install', 'package' => $packageD],
            ['job' => 'install', 'package' => $packageG2],
            ['job' => 'install', 'package' => $packageC2],
            ['job' => 'install', 'package' => $packageE],
            ['job' => 'install', 'package' => $packageB],
            ['job' => 'install', 'package' => $packageA],
        ]);

        // verify that the code path leading to a negative literal resulting in a positive learned literal is actually
        // executed
        $this->assertTrue($this->solver->testFlagLearnedPositiveLiteral);
    }

    protected function reposComplete(): void
    {
        $this->repoSet->addRepository($this->repo);
        $this->repoSet->addRepository($this->repoLocked);
    }

    protected function createSolver(): void
    {
        $io = new NullIO();
        $this->pool = $this->repoSet->createPool($this->request, $io);
        $this->solver = new Solver($this->policy, $this->pool, $io);
    }

    /**
     * @param array<array{job: string, package?: PackageInterface, from?: PackageInterface, to?: PackageInterface}> $expected
     */
    protected function checkSolverResult(array $expected): void
    {
        $this->createSolver();
        $transaction = $this->solver->solve($this->request);

        $result = [];
        foreach ($transaction->getOperations() as $operation) {
            if ($operation instanceof UpdateOperation) {
                $result[] = [
                    'job' => 'update',
                    'from' => $operation->getInitialPackage(),
                    'to' => $operation->getTargetPackage(),
                ];
            } elseif ($operation instanceof MarkAliasInstalledOperation || $operation instanceof MarkAliasUninstalledOperation) {
                $result[] = [
                    'job' => $operation->getOperationType(),
                    'package' => $operation->getPackage(),
                ];
            } elseif ($operation instanceof UninstallOperation || $operation instanceof InstallOperation) {
                $job = ('uninstall' === $operation->getOperationType() ? 'remove' : 'install');
                $result[] = [
                    'job' => $job,
                    'package' => $operation->getPackage(),
                ];
            } else {
                throw new \LogicException('Unexpected operation: '.get_class($operation));
            }
        }

        $expectedReadable = [];
        foreach ($expected as $op) {
            $expectedReadable[] = array_map('strval', $op);
        }
        $resultReadable = [];
        foreach ($result as $op) {
            $resultReadable[] = array_map('strval', $op);
        }

        $this->assertEquals($expectedReadable, $resultReadable);
        $this->assertEquals($expected, $result);
    }
}
