<?php

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
    public function testIsIgnored($req)
    {
        $platformRequirementFilter = new IgnoreNothingPlatformRequirementFilter();

        $this->assertFalse($platformRequirementFilter->isIgnored($req));
    }

    /**
     * @return array<string, mixed[]>
     */
    public function dataIsIgnored()
    {
        return array(
            'php is not ignored' => array('php'),
            'monolog/monolog is not ignored' => array('monolog/monolog'),
        );
    }
}
