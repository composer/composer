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

use Composer\Repository\InstalledRepository;
use Composer\Repository\ArrayRepository;
use Composer\Repository\InstalledArrayRepository;
use Composer\Package\Link;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Test\TestCase;

class InstalledRepositoryTest extends TestCase
{
    public function testFindPackagesWithReplacersAndProviders()
    {
        $arrayRepoOne = new InstalledArrayRepository;
        $arrayRepoOne->addPackage($foo = $this->getPackage('foo', '1'));
        $arrayRepoOne->addPackage($foo2 = $this->getPackage('foo', '2'));

        $arrayRepoTwo = new InstalledArrayRepository;
        $arrayRepoTwo->addPackage($bar = $this->getPackage('bar', '1'));
        $arrayRepoTwo->addPackage($bar2 = $this->getPackage('bar', '2'));

        $foo->setReplaces(array(new Link('foo', 'provided', new MatchAllConstraint())));
        $bar2->setProvides(array(new Link('bar', 'provided', new MatchAllConstraint())));

        $repo = new InstalledRepository(array($arrayRepoOne, $arrayRepoTwo));

        $this->assertEquals(array($foo2), $repo->findPackagesWithReplacersAndProviders('foo', '2'));
        $this->assertEquals(array($bar), $repo->findPackagesWithReplacersAndProviders('bar', '1'));
        $this->assertEquals(array($foo, $bar2), $repo->findPackagesWithReplacersAndProviders('provided'));
    }

    public function testAddRepository()
    {
        $arrayRepoOne = new ArrayRepository;

        $this->setExpectedException('LogicException');

        new InstalledRepository(array($arrayRepoOne));
    }
}
