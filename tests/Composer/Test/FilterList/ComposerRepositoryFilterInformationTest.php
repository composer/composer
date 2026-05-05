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
            'lists' => ['company-policy', 'aikido'],
        ]);

        self::assertTrue($info->metadata);
        self::assertSame(['company-policy', 'aikido'], $info->lists);
    }

    public function testFromDataDropsReservedListNamesAdvertisedByRepository(): void
    {
        $info = ComposerRepositoryFilterInformation::fromData([
            'lists' => ['advisories', 'company-policy', 'abandoned'],
        ]);

        self::assertSame(['company-policy'], $info->lists);
    }

    public function testFromDataDropsListNamesWithReservedPrefixAdvertisedByRepository(): void
    {
        $info = ComposerRepositoryFilterInformation::fromData([
            'lists' => ['ignore-foo', 'ignoremalware', 'company-policy'],
        ]);

        self::assertSame(['company-policy'], $info->lists);
    }
}
