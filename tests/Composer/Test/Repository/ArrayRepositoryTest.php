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

namespace Composer\Test\Repository;

use Composer\Repository\ArrayRepository;
use Composer\Package\MemoryPackage;

class ArrayRepositoryTest extends \PHPUnit_Framework_TestCase
{
    public function testAddPackage()
    {
        $repo = new ArrayRepository;
        $repo->addPackage(new MemoryPackage('foo', '1'));

        $this->assertEquals(1, count($repo));
    }

    public function testRemovePackage()
    {
        $package = new MemoryPackage('bar', '2');

        $repo = new ArrayRepository;
        $repo->addPackage(new MemoryPackage('foo', '1'));
        $repo->addPackage($package);

        $this->assertEquals(2, count($repo));

        $repo->removePackage(new MemoryPackage('foo', '1'));

        $this->assertEquals(1, count($repo));
        $this->assertEquals(array($package), $repo->getPackages());
    }

    public function testHasPackage()
    {
        $repo = new ArrayRepository;
        $repo->addPackage(new MemoryPackage('foo', '1'));
        $repo->addPackage(new MemoryPackage('bar', '2'));

        $this->assertTrue($repo->hasPackage(new MemoryPackage('foo', '1')));
        $this->assertFalse($repo->hasPackage(new MemoryPackage('bar', '1')));
    }
}
