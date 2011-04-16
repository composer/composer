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

use Composer\DependencyResolver\MemoryPackage;

class PackageTest extends \PHPUnit_Framework_TestCase
{
    public function testPackage()
    {
        $package = new MemoryPackage('foo', '1', 'beta', 21);

        $this->assertEquals('foo', $package->getName());
        $this->assertEquals('1', $package->getVersion());
        $this->assertEquals('beta', $package->getReleaseType());
        $this->assertEquals(21, $package->getId());

        $this->assertEquals('foo-beta-1', (string) $package);
    }
}
