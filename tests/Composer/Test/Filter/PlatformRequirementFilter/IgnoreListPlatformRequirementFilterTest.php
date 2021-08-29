<?php

namespace Composer\Test\Filter\PlatformRequirementFilter;

use Composer\Filter\PlatformRequirementFilter\IgnoreListPlatformRequirementFilter;
use Composer\Test\TestCase;

final class IgnoreListPlatformRequirementFilterTest extends TestCase
{
    /**
     * @dataProvider dataIsIgnored
     *
     * @param string[] $reqList
     * @param string $req
     * @param bool $expectIgnored
     */
    public function testIsIgnored(array $reqList, $req, $expectIgnored)
    {
        $platformRequirementFilter = new IgnoreListPlatformRequirementFilter($reqList);

        $this->assertSame($expectIgnored, $platformRequirementFilter->isIgnored($req));
    }

    /**
     * @return array<string, mixed[]>
     */
    public function dataIsIgnored()
    {
        return array(
            'ext-json is ignored if listed' => array(array('ext-json', 'monolog/monolog'), 'ext-json', true),
            'php is not ignored if not listed' => array(array('ext-json', 'monolog/monolog'), 'php', false),
            'monolog/monolog is not ignored even if listed' => array(array('ext-json', 'monolog/monolog'), 'monolog/monolog', false),
            'ext-json is ignored if ext-* is listed' => array(array('ext-*'), 'ext-json', true),
            'php is ignored if php* is listed' => array(array('ext-*', 'php*'), 'php', true),
            'ext-json is ignored if * is listed' => array(array('foo', '*'), 'ext-json', true),
            'php is ignored if * is listed' => array(array('*', 'foo'), 'php', true),
            'monolog/monolog is not ignored even if * or monolog/* are listed' => array(array('*', 'monolog/*'), 'monolog/monolog', false),
            'empty list entry does not ignore' => array(array(''), 'ext-foo', false),
            'empty array does not ignore' => array(array(), 'ext-foo', false),
            'list entries are not completing each other' => array(array('ext-', 'foo'), 'ext-foo', false),
        );
    }
}
