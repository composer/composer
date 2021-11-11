<?php

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
    public function testIsIgnored($req, $expectIgnored)
    {
        $platformRequirementFilter = new IgnoreAllPlatformRequirementFilter();

        $this->assertSame($expectIgnored, $platformRequirementFilter->isIgnored($req));
    }

    /**
     * @return array<string, mixed[]>
     */
    public function dataIsIgnored()
    {
        return array(
            'php is ignored' => array('php', true),
            'monolog/monolog is not ignored' => array('monolog/monolog', false),
        );
    }
}
