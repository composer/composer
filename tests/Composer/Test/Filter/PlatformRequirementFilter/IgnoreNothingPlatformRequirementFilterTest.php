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

use Composer\Filter\PlatformRequirementFilter\IgnoreNothingPlatformRequirementFilter;
use Composer\Test\TestCase;

final class IgnoreNothingPlatformRequirementFilterTest extends TestCase
{
    /**
     * @dataProvider dataIsIgnored
     */
    public function testIsIgnored(string $req): void
    {
        $platformRequirementFilter = new IgnoreNothingPlatformRequirementFilter();

        self::assertFalse($platformRequirementFilter->isIgnored($req));
        self::assertFalse($platformRequirementFilter->isUpperBoundIgnored($req));
    }

    /**
     * @return array<string, mixed[]>
     */
    public static function dataIsIgnored(): array
    {
        return [
            'php is not ignored' => ['php'],
            'monolog/monolog is not ignored' => ['monolog/monolog'],
        ];
    }
}
