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

namespace Composer\Test\Filter\PlatformRequirementFilter;

use Composer\Filter\PlatformRequirementFilter\IgnoreAllPlatformRequirementFilter;
use Composer\Test\TestCase;

final class IgnoreAllPlatformRequirementFilterTest extends TestCase
{
    /**
     * @dataProvider dataIsIgnored
     *
     * @param string $req
     * @param bool $expectIgnored
     */
    public function testIsIgnored($req, $expectIgnored): void
    {
        $platformRequirementFilter = new IgnoreAllPlatformRequirementFilter();

        $this->assertSame($expectIgnored, $platformRequirementFilter->isIgnored($req));
    }

    /**
     * @return array<string, mixed[]>
     */
    public function dataIsIgnored(): array
    {
        return array(
            'php is ignored' => array('php', true),
            'monolog/monolog is not ignored' => array('monolog/monolog', false),
        );
    }
}
