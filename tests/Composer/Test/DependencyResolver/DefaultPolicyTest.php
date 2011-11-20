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
use Composer\DependencyResolver\Literal;
use Composer\Package\Link;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Test\TestCase;

class DefaultPolicyTest extends TestCase
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

        $this->policy = new DefaultPolicy;
    }

    public function testSelectSingle()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->pool->addRepository($this->repo);

        $literals = array(new Literal($packageA, true));
        $expected = array(new Literal($packageA, true));

        $selected = $this->policy->selectPreferedPackages($this->pool, array(), $literals);

        $this->assertEquals($expected, $selected);
    }

    public function testSelectNewest()
    {
        $this->repo->addPackage($packageA1 = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageA2 = $this->getPackage('A', '2.0'));
        $this->pool->addRepository($this->repo);

        $literals = array(new Literal($packageA1, true), new Literal($packageA2, true));
        $expected = array(new Literal($packageA2, true));

        $selected = $this->policy->selectPreferedPackages($this->pool, array(), $literals);

        $this->assertEquals($expected, $selected);
    }

    public function testSelectNewestOverInstalled()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '2.0'));
        $this->repoInstalled->addPackage($packageAInstalled = $this->getPackage('A', '1.0'));
        $this->pool->addRepository($this->repoInstalled);
        $this->pool->addRepository($this->repo);

        $literals = array(new Literal($packageA, true), new Literal($packageAInstalled, true));
        $expected = array(new Literal($packageA, true));

        $selected = $this->policy->selectPreferedPackages($this->pool, $this->mapFromRepo($this->repoInstalled), $literals);

        $this->assertEquals($expected, $selected);
    }

    public function testSelectLastRepo()
    {
        $this->repoImportant = new ArrayRepository;

        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repoImportant->addPackage($packageAImportant = $this->getPackage('A', '1.0'));

        $this->pool->addRepository($this->repoInstalled);
        $this->pool->addRepository($this->repo);
        $this->pool->addRepository($this->repoImportant);

        $literals = array(new Literal($packageA, true), new Literal($packageAImportant, true));
        $expected = array(new Literal($packageAImportant, true));

        $selected = $this->policy->selectPreferedPackages($this->pool, array(), $literals);

        $this->assertEquals($expected, $selected);
    }

    public function testSelectAllProviders()
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '2.0'));

        $packageA->setProvides(array(new Link('A', 'X', new VersionConstraint('==', '1.0'), 'provides')));
        $packageB->setProvides(array(new Link('B', 'X', new VersionConstraint('==', '1.0'), 'provides')));

        $this->pool->addRepository($this->repo);

        $literals = array(new Literal($packageA, true), new Literal($packageB, true));
        $expected = $literals;

        $selected = $this->policy->selectPreferedPackages($this->pool, array(), $literals);

        $this->assertEquals($expected, $selected);
    }

    public function testPreferNonReplacingFromSameRepo()
    {

        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '2.0'));

        $packageB->setReplaces(array(new Link('B', 'A', new VersionConstraint('==', '1.0'), 'replaces')));

        $this->pool->addRepository($this->repo);

        $literals = array(new Literal($packageA, true), new Literal($packageB, true));
        $expected = array(new Literal($packageA, true), new Literal($packageB, true));

        $selected = $this->policy->selectPreferedPackages($this->pool, array(), $literals);

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
}
