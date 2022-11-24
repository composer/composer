<?php declare(strict_types=1);

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
    public function testSetSameRepository(): void
    {
        $package = $this->getMockForAbstractClass('Composer\Package\BasePackage', ['foo']);
        $repository = $this->getMockBuilder('Composer\Repository\RepositoryInterface')->getMock();

        $package->setRepository($repository);
        try {
            $package->setRepository($repository);
        } catch (\Exception $e) {
            $this->fail('Set against the same repository is allowed.');
        }
    }

    public function testSetAnotherRepository(): void
    {
        self::expectException('LogicException');

        $package = $this->getMockForAbstractClass('Composer\Package\BasePackage', ['foo']);

        $package->setRepository($this->getMockBuilder('Composer\Repository\RepositoryInterface')->getMock());
        $package->setRepository($this->getMockBuilder('Composer\Repository\RepositoryInterface')->getMock());
    }

    /**
     * @dataProvider provideFormattedVersions
     */
    public function testFormatVersionForDevPackage(string $sourceReference, bool $truncate, string $expected): void
    {
        $package = $this->getMockForAbstractClass('\Composer\Package\BasePackage', [], '', false);
        $package->expects($this->once())->method('isDev')->will($this->returnValue(true));
        $package->expects($this->any())->method('getSourceType')->will($this->returnValue('git'));
        $package->expects($this->once())->method('getPrettyVersion')->will($this->returnValue('PrettyVersion'));
        $package->expects($this->any())->method('getSourceReference')->will($this->returnValue($sourceReference));

        $this->assertSame($expected, $package->getFullPrettyVersion($truncate));
    }

    public static function provideFormattedVersions(): array
    {
        return [
            [
                'sourceReference' => 'v2.1.0-RC2',
                'truncate' => true,
                'expected' => 'PrettyVersion v2.1.0-RC2',
            ],
            [
                'sourceReference' => 'bbf527a27356414bfa9bf520f018c5cb7af67c77',
                'truncate' => true,
                'expected' => 'PrettyVersion bbf527a',
            ],
            [
                'sourceReference' => 'v1.0.0',
                'truncate' => false,
                'expected' => 'PrettyVersion v1.0.0',
            ],
            [
                'sourceReference' => 'bbf527a27356414bfa9bf520f018c5cb7af67c77',
                'truncate' => false,
                'expected' => 'PrettyVersion bbf527a27356414bfa9bf520f018c5cb7af67c77',
            ],
        ];
    }

    /**
     * @param string[] $packageNames
     * @param non-empty-string $wrap
     *
     * @dataProvider dataPackageNamesToRegexp
     */
    public function testPackageNamesToRegexp(array $packageNames, $wrap, string $expectedRegexp): void
    {
        $regexp = BasePackage::packageNamesToRegexp($packageNames, $wrap);

        $this->assertSame($expectedRegexp, $regexp);
    }

    /**
     * @return mixed[][]
     */
    public static function dataPackageNamesToRegexp(): array
    {
        return [
            [
                ['ext-*', 'monolog/monolog'], '{^%s$}i', '{^ext\-.*|monolog/monolog$}i',
                ['php'], '{^%s$}i', '{^php$}i',
                ['*'], '{^%s$}i', '{^.*$}i',
                ['foo', 'bar'], '§%s§', '§foo|bar§',
            ],
        ];
    }
}
