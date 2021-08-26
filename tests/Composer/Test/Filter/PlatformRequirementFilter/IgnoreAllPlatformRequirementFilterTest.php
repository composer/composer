<?php

namespace Composer\Test\Filter\PlatformRequirementFilter;

use Composer\Filter\PlatformRequirementFilter\IgnoreAllPlatformRequirementFilter;
use Composer\Test\TestCase;

final class IgnoreAllPlatformRequirementFilterTest extends TestCase
{
    /**
     * @dataProvider dataIsReqIgnored
     *
     * @param string $req
     * @param bool $expectIgnored
     */
    public function testIsReqIgnored($req, $expectIgnored)
    {
        $platformRequirementFilter = new IgnoreAllPlatformRequirementFilter();

        $this->assertSame($expectIgnored, $platformRequirementFilter->isReqIgnored($req));
    }

    /**
     * @return array<string, mixed[]>
     */
    public function dataIsReqIgnored()
    {
        return array(
            'php is ignored' => array('php', true),
            'monolog/monolog is not ignored' => array('monolog/monolog', false),
        );
    }
}
