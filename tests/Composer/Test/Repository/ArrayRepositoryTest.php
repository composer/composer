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
use Composer\Test\TestCase;

class ArrayRepositoryTest extends TestCase
{
    public function testAddPackage()
    {
        $repo = new ArrayRepository;
        $repo->addPackage($this->getPackage('foo', '1'));

        $this->assertEquals(1, count($repo));
    }

    public function testRemovePackage()
    {
        $package = $this->getPackage('bar', '2');

        $repo = new ArrayRepository;
        $repo->addPackage($this->getPackage('foo', '1'));
        $repo->addPackage($package);

        $this->assertEquals(2, count($repo));

        $repo->removePackage($this->getPackage('foo', '1'));

        $this->assertEquals(1, count($repo));
        $this->assertEquals(array($package), $repo->getPackages());
    }

    public function testHasPackage()
    {
        $repo = new ArrayRepository;
        $repo->addPackage($this->getPackage('foo', '1'));
        $repo->addPackage($this->getPackage('bar', '2'));

        $this->assertTrue($repo->hasPackage($this->getPackage('foo', '1')));
        $this->assertFalse($repo->hasPackage($this->getPackage('bar', '1')));
    }
}
