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
    public function testAddLiteral()
    {
        $repo = new ArrayRepository;
        $repo->addPackage(new MemoryPackage('foo', '1'));

        $this->assertEquals(1, count($repo));
    }
}
