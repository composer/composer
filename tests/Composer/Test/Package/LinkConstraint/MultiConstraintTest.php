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

namespace Composer\Test\Package\LinkConstraint;

use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Package\LinkConstraint\MultiConstraint;

class MultiConstraintTest extends \PHPUnit_Framework_TestCase
{
    public function testMultiVersionMatchSucceeds()
    {
        $versionRequireStart = new VersionConstraint('>', '1.0');
        $versionRequireEnd = new VersionConstraint('<', '1.2');
        $versionProvide = new VersionConstraint('==', '1.1');

        $multiRequire = new MultiConstraint(array($versionRequireStart, $versionRequireEnd));

        $this->assertTrue($multiRequire->matches($versionProvide));
    }

    public function testMultiVersionProvidedMatchSucceeds()
    {
        $versionRequireStart = new VersionConstraint('>', '1.0');
        $versionRequireEnd = new VersionConstraint('<', '1.2');
        $versionProvideStart = new VersionConstraint('>=', '1.1');
        $versionProvideEnd = new VersionConstraint('<', '2.0');

        $multiRequire = new MultiConstraint(array($versionRequireStart, $versionRequireEnd));
        $multiProvide = new MultiConstraint(array($versionProvideStart, $versionProvideEnd));

        $this->assertTrue($multiRequire->matches($multiProvide));
    }

    public function testMultiVersionMatchFails()
    {
        $versionRequireStart = new VersionConstraint('>', '1.0');
        $versionRequireEnd = new VersionConstraint('<', '1.2');
        $versionProvide = new VersionConstraint('==', '1.2');

        $multiRequire = new MultiConstraint(array($versionRequireStart, $versionRequireEnd));

        $this->assertFalse($multiRequire->matches($versionProvide));
    }
}
