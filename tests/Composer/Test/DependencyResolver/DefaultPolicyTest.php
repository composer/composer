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
use Composer\Repository\RepositoryInterface;
use Composer\DependencyResolver\DefaultPolicy;
use Composer\DependencyResolver\Pool;
use Composer\Package\Link;
use Composer\Package\AliasPackage;
use Composer\Semver\Constraint\Constraint;
use Composer\TestCase;

class DefaultPolicyTest extends TestCase
{
    protected $pool;
    protected $repo;
    protected $repoInstalled;
    protected $request;
    protected $policy;

    public function setUp()
    {
        $this->pool = new Pool('dev');
        $this->repo = new ArrayRepository;
        $this->repoInstalled = new ArrayRepository;

        $this->policy = new DefaultPolicy;
    }

    public function testSelectSingle()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->pool->addRepository($this->repo);

        $literals = array($packageA->getId());
        $expected = array($packageA->getId());

        $selected = $this->policy->selectPreferredPackages($this->pool, array(), $literals);

        $this->assertEquals($expected, $selected);
    }

    public function testSelectNewest()
    {
        $this->repo->addPackage($packageA1 = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageA2 = $this->getPackage('A', '2.0'));
        $this->pool->addRepository($this->repo);

        $literals = array($packageA1->getId(), $packageA2->getId());
        $expected = array($packageA2->getId());

        $selected = $this->policy->selectPreferredPackages($this->pool, array(), $literals);

        $this->assertEquals($expected, $selected);
    }

    public function testSelectNewestPicksLatest()
    {
        $this->repo->addPackage($packageA1 = $this->getPackage('A', '1.0.0'));
        $this->repo->addPackage($packageA2 = $this->getPackage('A', '1.0.1-alpha'));
        $this->pool->addRepository($this->repo);

        $literals = array($packageA1->getId(), $packageA2->getId());
        $expected = array($packageA2->getId());

        $selected = $this->policy->selectPreferredPackages($this->pool, array(), $literals);

        $this->assertEquals($expected, $selected);
    }

    public function testSelectNewestPicksLatestStableWithPreferStable()
    {
        $this->repo->addPackage($packageA1 = $this->getPackage('A', '1.0.0'));
        $this->repo->addPackage($packageA2 = $this->getPackage('A', '1.0.1-alpha'));
        $this->pool->addRepository($this->repo);

        $literals = array($packageA1->getId(), $packageA2->getId());
        $expected = array($packageA1->getId());

        $policy = new DefaultPolicy(true);
        $selected = $policy->selectPreferredPackages($this->pool, array(), $literals);

        $this->assertEquals($expected, $selected);
    }

    public function testSelectNewestWithDevPicksNonDev()
    {
        $this->repo->addPackage($packageA1 = $this->getPackage('A', 'dev-foo'));
        $this->repo->addPackage($packageA2 = $this->getPackage('A', '1.0.0'));
        $this->pool->addRepository($this->repo);

        $literals = array($packageA1->getId(), $packageA2->getId());
        $expected = array($packageA2->getId());

        $selected = $this->policy->selectPreferredPackages($this->pool, array(), $literals);

        $this->assertEquals($expected, $selected);
    }

    public function testSelectNewestOverInstalled()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '2.0'));
        $this->repoInstalled->addPackage($packageAInstalled = $this->getPackage('A', '1.0'));
        $this->pool->addRepository($this->repoInstalled);
        $this->pool->addRepository($this->repo);

        $literals = array($packageA->getId(), $packageAInstalled->getId());
        $expected = array($packageA->getId());

        $selected = $this->policy->selectPreferredPackages($this->pool, $this->mapFromRepo($this->repoInstalled), $literals);

        $this->assertEquals($expected, $selected);
    }

    public function testSelectFirstRepo()
    {
        $this->repoImportant = new ArrayRepository;

        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repoImportant->addPackage($packageAImportant = $this->getPackage('A', '1.0'));

        $this->pool->addRepository($this->repoInstalled);
        $this->pool->addRepository($this->repoImportant);
        $this->pool->addRepository($this->repo);

        $literals = array($packageA->getId(), $packageAImportant->getId());
        $expected = array($packageAImportant->getId());

        $selected = $this->policy->selectPreferredPackages($this->pool, array(), $literals);

        $this->assertEquals($expected, $selected);
    }

    public function testSelectLocalReposFirst()
    {
        $this->repoImportant = new ArrayRepository;

        $this->repo->addPackage($packageA = $this->getPackage('A', 'dev-master'));
        $this->repo->addPackage($packageAAlias = new AliasPackage($packageA, '2.1.9999999.9999999-dev', '2.1.x-dev'));
        $this->repoImportant->addPackage($packageAImportant = $this->getPackage('A', 'dev-feature-a'));
        $this->repoImportant->addPackage($packageAAliasImportant = new AliasPackage($packageAImportant, '2.1.9999999.9999999-dev', '2.1.x-dev'));
        $this->repoImportant->addPackage($packageA2Important = $this->getPackage('A', 'dev-master'));
        $this->repoImportant->addPackage($packageA2AliasImportant = new AliasPackage($packageA2Important, '2.1.9999999.9999999-dev', '2.1.x-dev'));
        $packageAAliasImportant->setRootPackageAlias(true);

        $this->pool->addRepository($this->repoInstalled);
        $this->pool->addRepository($this->repoImportant);
        $this->pool->addRepository($this->repo);

        $packages = $this->pool->whatProvides('a', new Constraint('=', '2.1.9999999.9999999-dev'));
        $literals = array();
        foreach ($packages as $package) {
            $literals[] = $package->getId();
        }

        $expected = array($packageAAliasImportant->getId());

        $selected = $this->policy->selectPreferredPackages($this->pool, array(), $literals);

        $this->assertEquals($expected, $selected);
    }

    public function testSelectAllProviders()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '2.0'));

        $packageA->setProvides(array(new Link('A', 'X', new Constraint('==', '1.0'), 'provides')));
        $packageB->setProvides(array(new Link('B', 'X', new Constraint('==', '1.0'), 'provides')));

        $this->pool->addRepository($this->repo);

        $literals = array($packageA->getId(), $packageB->getId());
        $expected = $literals;

        $selected = $this->policy->selectPreferredPackages($this->pool, array(), $literals);

        $this->assertEquals($expected, $selected);
    }

    public function testPreferNonReplacingFromSameRepo()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '2.0'));

        $packageB->setReplaces(array(new Link('B', 'A', new Constraint('==', '1.0'), 'replaces')));

        $this->pool->addRepository($this->repo);

        $literals = array($packageA->getId(), $packageB->getId());
        $expected = $literals;

        $selected = $this->policy->selectPreferredPackages($this->pool, array(), $literals);

        $this->assertEquals($expected, $selected);
    }

    public function testPreferReplacingPackageFromSameVendor()
    {
        // test with default order
        $this->repo->addPackage($packageB = $this->getPackage('vendor-b/replacer', '1.0'));
        $this->repo->addPackage($packageA = $this->getPackage('vendor-a/replacer', '1.0'));

        $packageA->setReplaces(array(new Link('vendor-a/replacer', 'vendor-a/package', new Constraint('==', '1.0'), 'replaces')));
        $packageB->setReplaces(array(new Link('vendor-b/replacer', 'vendor-a/package', new Constraint('==', '1.0'), 'replaces')));

        $this->pool->addRepository($this->repo);

        $literals = array($packageA->getId(), $packageB->getId());
        $expected = $literals;

        $selected = $this->policy->selectPreferredPackages($this->pool, array(), $literals, 'vendor-a/package');
        $this->assertEquals($expected, $selected);

        // test with reversed order in repo
        $repo = new ArrayRepository;
        $repo->addPackage($packageA = clone $packageA);
        $repo->addPackage($packageB = clone $packageB);

        $pool = new Pool('dev');
        $pool->addRepository($this->repo);

        $literals = array($packageA->getId(), $packageB->getId());
        $expected = $literals;

        $selected = $this->policy->selectPreferredPackages($this->pool, array(), $literals, 'vendor-a/package');
        $this->assertEquals($expected, $selected);
    }

    protected function mapFromRepo(RepositoryInterface $repo)
    {
        $map = array();
        foreach ($repo->getPackages() as $package) {
            $map[$package->getId()] = true;
        }

        return $map;
    }

    public function testSelectLowest()
    {
        $policy = new DefaultPolicy(false, true);

        $this->repo->addPackage($packageA1 = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageA2 = $this->getPackage('A', '2.0'));
        $this->pool->addRepository($this->repo);

        $literals = array($packageA1->getId(), $packageA2->getId());
        $expected = array($packageA1->getId());

        $selected = $policy->selectPreferredPackages($this->pool, array(), $literals);

        $this->assertEquals($expected, $selected);
    }
}
