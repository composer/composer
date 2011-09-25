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

namespace Composer\Test\Package;

use Composer\Package\MemoryPackage;

class MemoryPackageTest extends \PHPUnit_Framework_TestCase
{
    public function testMemoryPackage()
    {
        $package = new MemoryPackage('foo', '1-beta');

        $this->assertEquals('foo', $package->getName());
        $this->assertEquals('1-beta', $package->getVersion());

        $this->assertEquals('foo-1-beta', (string) $package);
    }
}
