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

use Composer\Repository\WritableArrayRepository;
use Composer\TestCase;

final class WritableArrayRepositoryTest extends TestCase
{
    public function testGetCanonicalPackagesReturnsDifferentVersionsOfSameNamedPackage()
    {
        $repository = new WritableArrayRepository();

        $repository->addPackage($this->getPackage('foo', 1));
        $repository->addPackage($this->getPackage('foo', 2));

        $this->assertCount(2, $repository->getCanonicalPackages());
    }
}
