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

use Composer\DependencyResolver\Pool;
use Composer\Repository\ArrayRepository;
use Composer\Package\BasePackage;
use Composer\TestCase;

class PoolTest extends TestCase
{
    public function testPool()
    {
        $pool = new Pool;
        $repo = new ArrayRepository;
        $package = $this->getPackage('foo', '1');

        $repo->addPackage($package);
        $pool->addRepository($repo);

        $this->assertEquals(array($package), $pool->whatProvides('foo'));
        $this->assertEquals(array($package), $pool->whatProvides('foo'));
    }

    public function testPoolIgnoresIrrelevantPackages()
    {
        $pool = new Pool('stable', array('bar' => BasePackage::STABILITY_BETA));
        $repo = new ArrayRepository;
        $repo->addPackage($package = $this->getPackage('bar', '1'));
        $repo->addPackage($betaPackage = $this->getPackage('bar', '1-beta'));
        $repo->addPackage($alphaPackage = $this->getPackage('bar', '1-alpha'));
        $repo->addPackage($package2 = $this->getPackage('foo', '1'));
        $repo->addPackage($rcPackage2 = $this->getPackage('foo', '1rc'));

        $pool->addRepository($repo);

        $this->assertEquals(array($package, $betaPackage), $pool->whatProvides('bar'));
        $this->assertEquals(array($package2), $pool->whatProvides('foo'));
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testGetPriorityForNotRegisteredRepository()
    {
        $pool = new Pool;
        $repository = new ArrayRepository;

        $pool->getPriority($repository);
    }

    public function testGetPriorityWhenRepositoryIsRegistered()
    {
        $pool = new Pool;
        $firstRepository = new ArrayRepository;
        $pool->addRepository($firstRepository);
        $secondRepository = new ArrayRepository;
        $pool->addRepository($secondRepository);

        $firstPriority = $pool->getPriority($firstRepository);
        $secondPriority = $pool->getPriority($secondRepository);

        $this->assertEquals(0, $firstPriority);
        $this->assertEquals(-1, $secondPriority);
    }

    public function testWhatProvidesSamePackageForDifferentRepositories()
    {
        $pool = new Pool;
        $firstRepository = new ArrayRepository;
        $secondRepository = new ArrayRepository;

        $firstPackage = $this->getPackage('foo', '1');
        $secondPackage = $this->getPackage('foo', '1');
        $thirdPackage = $this->getPackage('foo', '2');

        $firstRepository->addPackage($firstPackage);
        $secondRepository->addPackage($secondPackage);
        $secondRepository->addPackage($thirdPackage);

        $pool->addRepository($firstRepository);
        $pool->addRepository($secondRepository);

        $this->assertEquals(array($firstPackage, $secondPackage, $thirdPackage), $pool->whatProvides('foo'));
    }

    public function testWhatProvidesPackageWithConstraint()
    {
        $pool = new Pool;
        $repository = new ArrayRepository;

        $firstPackage = $this->getPackage('foo', '1');
        $secondPackage = $this->getPackage('foo', '2');

        $repository->addPackage($firstPackage);
        $repository->addPackage($secondPackage);

        $pool->addRepository($repository);

        $this->assertEquals(array($firstPackage, $secondPackage), $pool->whatProvides('foo'));
        $this->assertEquals(array($secondPackage), $pool->whatProvides('foo', $this->getVersionConstraint('==', '2')));
    }

    public function testPackageById()
    {
        $pool = new Pool;
        $repository = new ArrayRepository;
        $package = $this->getPackage('foo', '1');

        $repository->addPackage($package);
        $pool->addRepository($repository);

        $this->assertSame($package, $pool->packageById(1));
    }

    public function testWhatProvidesWhenPackageCannotBeFound()
    {
        $pool = new Pool;

        $this->assertEquals(array(), $pool->whatProvides('foo'));
    }
}
