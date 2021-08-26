<?php

namespace Composer\Test\Filter\PlatformRequirementFilter;

use Composer\Filter\PlatformRequirementFilter\IgnoreListPlatformRequirementFilter;
use Composer\Test\TestCase;

final class IgnoreListPlatformRequirementFilterTest extends TestCase
{
    /**
     * @dataProvider dataIsReqIgnored
     *
     * @param string[] $reqList
     * @param string $req
     * @param bool $expectIgnored
     */
    public function testIsReqIgnored(array $reqList, $req, $expectIgnored)
    {
        $platformRequirementFilter = new IgnoreListPlatformRequirementFilter($reqList);

        $this->assertSame($expectIgnored, $platformRequirementFilter->isReqIgnored($req));
    }

    /**
     * @return array<string, mixed[]>
     */
    public function dataIsReqIgnored()
    {
        return array(
            'ext-json is ignored if listed' => array(array('ext-json', 'monolog/monolog'), 'ext-json', true),
            'php is not ignored if not listed' => array(array('ext-json', 'monolog/monolog'), 'php', false),
            'monolog/monolog is not ignored even if listed' => array(array('ext-json', 'monolog/monolog'), 'monolog/monolog', false),
        );
    }
}
