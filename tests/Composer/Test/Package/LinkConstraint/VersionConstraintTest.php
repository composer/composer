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

class VersionConstraintTest extends \PHPUnit_Framework_TestCase
{
    public static function successfulVersionMatches()
    {
        return array(
            //    require    provide
            array('==', '1', '==', '1'),
            array('>=', '1', '>=', '2'),
            array('>=', '2', '>=', '1'),
            array('>=', '2', '>', '1'),
            array('<=', '2', '>=', '1'),
            array('>=', '1', '<=', '2'),
            array('==', '2', '>=', '2'),
            array('!=', '1', '!=', '1'),
            array('!=', '1', '==', '2'),
            array('!=', '1', '<',  '1'),
            array('!=', '1', '<=', '1'),
            array('!=', '1', '>',  '1'),
            array('!=', '1', '>=', '1'),
            array('==', 'dev-foo-bar', '==', 'dev-foo-bar'),
            array('==', 'dev-foo-xyz', '==', 'dev-foo-xyz'),
            array('>=', 'dev-foo-bar', '>=', 'dev-foo-xyz'),
            array('<=', 'dev-foo-bar', '<', 'dev-foo-xyz'),
            array('!=', 'dev-foo-bar', '<', 'dev-foo-xyz'),
            array('>=', 'dev-foo-bar', '!=', 'dev-foo-bar'),
            array('!=', 'dev-foo-bar', '!=', 'dev-foo-xyz'),
        );
    }

    /**
     * @dataProvider successfulVersionMatches
     */
    public function testVersionMatchSucceeds($requireOperator, $requireVersion, $provideOperator, $provideVersion)
    {
        $versionRequire = new VersionConstraint($requireOperator, $requireVersion);
        $versionProvide = new VersionConstraint($provideOperator, $provideVersion);

        $this->assertTrue($versionRequire->matches($versionProvide));
    }

    public static function failingVersionMatches()
    {
        return array(
            //    require    provide
            array('==', '1', '==', '2'),
            array('>=', '2', '<=', '1'),
            array('>=', '2', '<', '2'),
            array('<=', '2', '>', '2'),
            array('>', '2', '<=', '2'),
            array('<=', '1', '>=', '2'),
            array('>=', '2', '<=', '1'),
            array('==', '2', '<', '2'),
            array('!=', '1', '==', '1'),
            array('==', '1', '!=', '1'),
            array('==', 'dev-foo-dist', '==', 'dev-foo-zist'),
            array('==', 'dev-foo-bist', '==', 'dev-foo-aist'),
            array('<=', 'dev-foo-bist', '>=', 'dev-foo-aist'),
            array('>=', 'dev-foo-bist', '<', 'dev-foo-aist'),
            array('<',  '0.12', '==', 'dev-foo'), // branches are not comparable
            array('>',  '0.12', '==', 'dev-foo'), // branches are not comparable
        );
    }

    /**
     * @dataProvider failingVersionMatches
     */
    public function testVersionMatchFails($requireOperator, $requireVersion, $provideOperator, $provideVersion)
    {
        $versionRequire = new VersionConstraint($requireOperator, $requireVersion);
        $versionProvide = new VersionConstraint($provideOperator, $provideVersion);

        $this->assertFalse($versionRequire->matches($versionProvide));
    }

    public function testComparableBranches()
    {
        $versionRequire = new VersionConstraint('>', '0.12');
        $versionProvide = new VersionConstraint('==', 'dev-foo');

        $this->assertFalse($versionRequire->matches($versionProvide));
        $this->assertFalse($versionRequire->matchSpecific($versionProvide, true));

        $versionRequire = new VersionConstraint('<', '0.12');
        $versionProvide = new VersionConstraint('==', 'dev-foo');

        $this->assertFalse($versionRequire->matches($versionProvide));
        $this->assertTrue($versionRequire->matchSpecific($versionProvide, true));
    }
}
