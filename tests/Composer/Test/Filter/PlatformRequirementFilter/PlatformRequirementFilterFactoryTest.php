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

namespace Composer\Test\Filter\PlatformRequirementFilter;

use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterFactory;
use Composer\Test\TestCase;

final class PlatformRequirementFilterFactoryTest extends TestCase
{
    /**
     * @dataProvider dataFromBoolOrList
     *
     * @param mixed $boolOrList
     * @param class-string $expectedInstance
     */
    public function testFromBoolOrList($boolOrList, $expectedInstance): void
    {
        self::assertInstanceOf($expectedInstance, PlatformRequirementFilterFactory::fromBoolOrList($boolOrList));
    }

    /**
     * @return array<string, mixed[]>
     */
    public static function dataFromBoolOrList(): array
    {
        return [
            'true creates IgnoreAllFilter' => [true, 'Composer\Filter\PlatformRequirementFilter\IgnoreAllPlatformRequirementFilter'],
            'false creates IgnoreNothingFilter' => [false, 'Composer\Filter\PlatformRequirementFilter\IgnoreNothingPlatformRequirementFilter'],
            'list creates IgnoreListFilter' => [['php', 'ext-json'], 'Composer\Filter\PlatformRequirementFilter\IgnoreListPlatformRequirementFilter'],
        ];
    }

    public function testFromBoolThrowsExceptionIfTypeIsUnknown(): void
    {
        self::expectException('InvalidArgumentException');
        self::expectExceptionMessage('PlatformRequirementFilter: Unknown $boolOrList parameter NULL. Please report at https://github.com/composer/composer/issues/new.');

        PlatformRequirementFilterFactory::fromBoolOrList(null);
    }

    public function testIgnoreAll(): void
    {
        $platformRequirementFilter = PlatformRequirementFilterFactory::ignoreAll();

        self::assertInstanceOf('Composer\Filter\PlatformRequirementFilter\IgnoreAllPlatformRequirementFilter', $platformRequirementFilter);
    }

    public function testIgnoreNothing(): void
    {
        $platformRequirementFilter = PlatformRequirementFilterFactory::ignoreNothing();

        self::assertInstanceOf('Composer\Filter\PlatformRequirementFilter\IgnoreNothingPlatformRequirementFilter', $platformRequirementFilter);
    }
}
