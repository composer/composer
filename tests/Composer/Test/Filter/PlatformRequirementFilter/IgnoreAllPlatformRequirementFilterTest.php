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

use Composer\Filter\PlatformRequirementFilter\IgnoreAllPlatformRequirementFilter;
use Composer\Test\TestCase;

final class IgnoreAllPlatformRequirementFilterTest extends TestCase
{
    /**
     * @dataProvider dataIsIgnored
     */
    public function testIsIgnored(string $req, bool $expectIgnored): void
    {
        $platformRequirementFilter = new IgnoreAllPlatformRequirementFilter();

        $this->assertSame($expectIgnored, $platformRequirementFilter->isIgnored($req));
        $this->assertSame($expectIgnored, $platformRequirementFilter->isUpperBoundIgnored($req));
    }

    /**
     * @return array<string, mixed[]>
     */
    public static function dataIsIgnored(): array
    {
        return [
            'php is ignored' => ['php', true],
            'monolog/monolog is not ignored' => ['monolog/monolog', false],
        ];
    }
}
