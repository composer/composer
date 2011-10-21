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
use Composer\DependencyResolver\DefaultPolicy;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Literal;
use Composer\Package\MemoryPackage;
use Composer\Package\Link;
use Composer\Package\LinkConstraint\VersionConstraint;

class DefaultPolicyTest extends \PHPUnit_Framework_TestCase
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
        $this->repo->addPackage($packageA = new MemoryPackage('A', '1.0'));
        $this->pool->addRepository($this->repo);

        $literals = array(new Literal($packageA, true));
        $expected = array(new Literal($packageA, true));

        $selected = $this->policy->selectPreferedPackages($this->pool, $this->repoInstalled, $literals);

        $this->assertEquals($expected, $selected);
    }

    public function testSelectNewest()
    {
        $this->repo->addPackage($packageA1 = new MemoryPackage('A', '1.0'));
        $this->repo->addPackage($packageA2 = new MemoryPackage('A', '2.0'));
        $this->pool->addRepository($this->repo);

        $literals = array(new Literal($packageA1, true), new Literal($packageA2, true));
        $expected = array(new Literal($packageA2, true));

        $selected = $this->policy->selectPreferedPackages($this->pool, $this->repoInstalled, $literals);

        $this->assertEquals($expected, $selected);
    }

    public function testSelectInstalled()
    {
        $this->repo->addPackage($packageA = new MemoryPackage('A', '1.0'));
        $this->repoInstalled->addPackage($packageAInstalled = new MemoryPackage('A', '1.0'));
        $this->pool->addRepository($this->repo);
        $this->pool->addRepository($this->repoInstalled);

        $literals = array(new Literal($packageA, true), new Literal($packageAInstalled, true));
        $expected = array(new Literal($packageAInstalled, true));

        $selected = $this->policy->selectPreferedPackages($this->pool, $this->repoInstalled, $literals);

        $this->assertEquals($expected, $selected);
    }

    public function testSelectLastRepo()
    {
        $this->markTestIncomplete();

        $this->repoImportant = new ArrayRepository;

        $this->repo->addPackage($packageA = new MemoryPackage('A', '1.0'));
        $this->repoImportant->addPackage($packageAImportant = new MemoryPackage('A', '1.0'));

        $this->pool->addRepository($this->repo);
        $this->pool->addRepository($this->repoImportant);

        $literals = array(new Literal($packageA, true), new Literal($packageAImportant, true));
        $expected = array(new Literal($packageAImportant, true));

        $selected = $this->policy->selectPreferedPackages($this->pool, $this->repoInstalled, $literals);

        $this->assertEquals($expected, $selected);
    }
}
