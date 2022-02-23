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

use Composer\Filter\PlatformRequirementFilter\IgnoreNothingPlatformRequirementFilter;
use Composer\Test\TestCase;

final class IgnoreNothingPlatformRequirementFilterTest extends TestCase
{
    /**
     * @dataProvider dataIsIgnored
     *
     * @param string $req
     */
    public function testIsIgnored(string $req): void
    {
        $platformRequirementFilter = new IgnoreNothingPlatformRequirementFilter();

        $this->assertFalse($platformRequirementFilter->isIgnored($req));
    }

    /**
     * @return array<string, mixed[]>
     */
    public function dataIsIgnored(): array
    {
        return array(
            'php is not ignored' => array('php'),
            'monolog/monolog is not ignored' => array('monolog/monolog'),
        );
    }
}
