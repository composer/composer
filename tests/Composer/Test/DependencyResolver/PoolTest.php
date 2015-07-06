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
        $package = $this->getPackage('foo', '1');

        $pool = new Pool(
            array($package),
            array(0)
        );

        $this->assertEquals(array($package), $pool->whatProvides('foo'));
        $this->assertEquals(array($package), $pool->whatProvides('foo'));
    }

    public function testWhatProvidesSamePackageForDifferentPriorities()
    {
        $firstPackage = $this->getPackage('foo', '1');
        $secondPackage = $this->getPackage('foo', '1');
        $thirdPackage = $this->getPackage('foo', '2');

        $pool = new Pool(
            array($firstPackage, $secondPackage, $thirdPackage),
            array(0, -1, -1)
        );

        $this->assertEquals(array($firstPackage, $secondPackage, $thirdPackage), $pool->whatProvides('foo'));
    }

    public function testWhatProvidesPackageWithConstraint()
    {
        $firstPackage = $this->getPackage('foo', '1');
        $secondPackage = $this->getPackage('foo', '2');

        $pool = new Pool(
            array($firstPackage, $secondPackage),
            array(0, 0)
        );

        $this->assertEquals(array($firstPackage, $secondPackage), $pool->whatProvides('foo'));
        $this->assertEquals(array($secondPackage), $pool->whatProvides('foo', $this->getVersionConstraint('==', '2')));
    }

    public function testPackageById()
    {
        $package = $this->getPackage('foo', '1');

        $pool = new Pool(array($package), array(0));

        $this->assertSame($package, $pool->packageById(1));
    }

    public function testWhatProvidesWhenPackageCannotBeFound()
    {
        $pool = new Pool(array(), array());

        $this->assertEquals(array(), $pool->whatProvides('foo'));
    }
}
