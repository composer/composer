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

use Composer\Package\BasePackage;

class BasePackageTest extends \PHPUnit_Framework_TestCase
{
    public function testSetSameRepository()
    {
        $package = $this->getMockForAbstractClass('Composer\Package\BasePackage', array('foo'));
        $repository = $this->getMock('Composer\Repository\RepositoryInterface');

        $package->setRepository($repository);
        try {
            $package->setRepository($repository);
        } catch (\Exception $e) {
            $this->fail('Set against the same repository is allowed.');
        }
    }

    /**
     * @expectedException LogicException
     */
    public function testSetAnotherRepository()
    {
        $package = $this->getMockForAbstractClass('Composer\Package\BasePackage', array('foo'));

        $package->setRepository($this->getMock('Composer\Repository\RepositoryInterface'));
        $package->setRepository($this->getMock('Composer\Repository\RepositoryInterface'));
    }
}
