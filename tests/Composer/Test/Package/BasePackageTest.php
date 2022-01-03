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
use Composer\Test\TestCase;

class BasePackageTest extends TestCase
{
    /**
     * @doesNotPerformAssertions
     */
    public function testSetSameRepository()
    {
        $package = $this->getMockForAbstractClass('Composer\Package\BasePackage', array('foo'));
        $repository = $this->getMockBuilder('Composer\Repository\RepositoryInterface')->getMock();

        $package->setRepository($repository);
        try {
            $package->setRepository($repository);
        } catch (\Exception $e) {
            $this->fail('Set against the same repository is allowed.');
        }
    }

    public function testSetAnotherRepository()
    {
        self::expectException('LogicException');

        $package = $this->getMockForAbstractClass('Composer\Package\BasePackage', array('foo'));

        $package->setRepository($this->getMockBuilder('Composer\Repository\RepositoryInterface')->getMock());
        $package->setRepository($this->getMockBuilder('Composer\Repository\RepositoryInterface')->getMock());
    }

    /**
     * @dataProvider provideFormattedVersions
     *
     * @param bool   $truncate
     * @param string $expected
     */
    public function testFormatVersionForDevPackage(BasePackage $package, $truncate, $expected)
    {
        $this->assertSame($expected, $package->getFullPrettyVersion($truncate));
    }

    public function provideFormattedVersions()
    {
        $data = array(
            array(
                'sourceReference' => 'v2.1.0-RC2',
                'truncate' => true,
                'expected' => 'PrettyVersion v2.1.0-RC2',
            ),
            array(
                'sourceReference' => 'bbf527a27356414bfa9bf520f018c5cb7af67c77',
                'truncate' => true,
                'expected' => 'PrettyVersion bbf527a',
            ),
            array(
                'sourceReference' => 'v1.0.0',
                'truncate' => false,
                'expected' => 'PrettyVersion v1.0.0',
            ),
            array(
                'sourceReference' => 'bbf527a27356414bfa9bf520f018c5cb7af67c77',
                'truncate' => false,
                'expected' => 'PrettyVersion bbf527a27356414bfa9bf520f018c5cb7af67c77',
            ),
        );

        $createPackage = function ($arr) {
            $package = $this->getMockForAbstractClass('\Composer\Package\BasePackage', array(), '', false);
            $package->expects($this->once())->method('isDev')->will($this->returnValue(true));
            $package->expects($this->any())->method('getSourceType')->will($this->returnValue('git'));
            $package->expects($this->once())->method('getPrettyVersion')->will($this->returnValue('PrettyVersion'));
            $package->expects($this->any())->method('getSourceReference')->will($this->returnValue($arr['sourceReference']));

            return array($package, $arr['truncate'], $arr['expected']);
        };

        return array_map($createPackage, $data);
    }

    /**
     * @param string[] $packageNames
     * @param string $wrap
     * @param string $expectedRegexp
     *
     * @dataProvider dataPackageNamesToRegexp
     */
    public function testPackageNamesToRegexp(array $packageNames, $wrap, $expectedRegexp)
    {
        $regexp = BasePackage::packageNamesToRegexp($packageNames, $wrap);

        $this->assertSame($expectedRegexp, $regexp);
    }

    /**
     * @return mixed[][]
     */
    public function dataPackageNamesToRegexp()
    {
        return array(
            array(
                array('ext-*', 'monolog/monolog'), '{^%s$}i', '{^ext\-.*|monolog/monolog$}i',
                array('php'), '{^%s$}i', '{^php$}i',
                array('*'), '{^%s$}i', '{^.*$}i',
                array('foo', 'bar'), '§%s§', '§foo|bar§',
            )
        );
    }
}
