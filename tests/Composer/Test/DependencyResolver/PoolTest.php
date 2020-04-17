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
use Composer\Test\TestCase;

class PoolTest extends TestCase
{
    public function testPool()
    {
        $package = $this->getPackage('foo', '1');

        $pool = $this->createPool(array($package));

        $this->assertEquals(array($package), $pool->whatProvides('foo'));
        $this->assertEquals(array($package), $pool->whatProvides('foo'));
    }

    public function testWhatProvidesPackageWithConstraint()
    {
        $firstPackage = $this->getPackage('foo', '1');
        $secondPackage = $this->getPackage('foo', '2');

        $pool = $this->createPool(array(
            $firstPackage,
            $secondPackage,
        ));

        $this->assertEquals(array($firstPackage, $secondPackage), $pool->whatProvides('foo'));
        $this->assertEquals(array($secondPackage), $pool->whatProvides('foo', $this->getVersionConstraint('==', '2')));
    }

    public function testPackageById()
    {
        $package = $this->getPackage('foo', '1');

        $pool = $this->createPool(array($package));

        $this->assertSame($package, $pool->packageById(1));
    }

    public function testWhatProvidesWhenPackageCannotBeFound()
    {
        $pool = $this->createPool();

        $this->assertEquals(array(), $pool->whatProvides('foo'));
    }

    protected function createPool(array $packages = array())
    {
        return new Pool($packages);
    }
}
