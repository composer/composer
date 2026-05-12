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

namespace Composer\Test\FilterList;

use Composer\FilterList\ComposerRepositoryFilterInformation;
use Composer\Test\TestCase;

class ComposerRepositoryFilterInformationTest extends TestCase
{
    public function testFromDataPassesThroughCustomLists(): void
    {
        $info = ComposerRepositoryFilterInformation::fromData([
            'metadata' => true,
            'lists' => ['company-policy' => ['enabled' => true], 'aikido' => ['enabled' => true]],
        ]);

        self::assertTrue($info->metadata);
        self::assertSame(['company-policy', 'aikido'], $info->lists);
    }

    public function testFromDataSkipsListsNotEnabled(): void
    {
        $info = ComposerRepositoryFilterInformation::fromData([
            'lists' => ['company-policy' => ['enabled' => true], 'aikido' => ['enabled' => false]],
        ]);

        self::assertSame(['company-policy'], $info->lists);
    }

    public function testFromDataSkipsListsWithMissingEnabledFlag(): void
    {
        $info = ComposerRepositoryFilterInformation::fromData([
            'lists' => ['company-policy' => ['enabled' => true], 'malware' => []],
        ]);

        self::assertSame(['company-policy'], $info->lists);
    }

    public function testFromDataSkipsListsWithScalarConfig(): void
    {
        $info = ComposerRepositoryFilterInformation::fromData([
            'lists' => ['company-policy' => ['enabled' => true], 'malware' => true],
        ]);

        self::assertSame(['company-policy'], $info->lists);
    }

    public function testFromDataDropsReservedListNamesAdvertisedByRepository(): void
    {
        $info = ComposerRepositoryFilterInformation::fromData([
            'lists' => [
                'advisories' => ['enabled' => true],
                'company-policy' => ['enabled' => true],
                'abandoned' => ['enabled' => true],
            ],
        ]);

        self::assertSame(['company-policy'], $info->lists);
    }

    public function testFromDataDropsListNamesWithReservedPrefixAdvertisedByRepository(): void
    {
        $info = ComposerRepositoryFilterInformation::fromData([
            'lists' => [
                'ignore-foo' => ['enabled' => true],
                'ignoremalware' => ['enabled' => true],
                'company-policy' => ['enabled' => true],
            ],
        ]);

        self::assertSame(['company-policy'], $info->lists);
    }
}
