<?php

namespace Composer\Test\Filter\PlatformRequirementFilter;

use Composer\Filter\PlatformRequirementFilter\IgnoreNothingPlatformRequirementFilter;
use Composer\Test\TestCase;

final class IgnoreNothingPlatformRequirementFilterTest extends TestCase
{
    /**
     * @dataProvider dataIsReqIgnored
     *
     * @param string $req
     */
    public function testIsReqIgnored($req)
    {
        $platformRequirementFilter = new IgnoreNothingPlatformRequirementFilter();

        $this->assertFalse($platformRequirementFilter->isReqIgnored($req));
    }

    /**
     * @return array<string, mixed[]>
     */
    public function dataIsReqIgnored()
    {
        return array(
            'php is not ignored' => array('php'),
            'monolog/monolog is not ignored' => array('monolog/monolog'),
        );
    }
}
