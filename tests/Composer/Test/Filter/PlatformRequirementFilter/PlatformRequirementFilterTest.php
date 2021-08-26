<?php

namespace Composer\Test\Filter\PlatformRequirementFilter;

use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilter;
use Composer\Test\TestCase;

final class PlatformRequirementFilterTest extends TestCase
{
    /**
     * @dataProvider dataFromBoolOrList
     *
     * @param mixed $boolOrList
     * @param class-string $expectedInstance
     */
    public function testFromBoolOrList($boolOrList, $expectedInstance)
    {
        $this->assertInstanceOf($expectedInstance, PlatformRequirementFilter::fromBoolOrList($boolOrList));
    }

    /**
     * @return array<string, mixed[]>
     */
    public function dataFromBoolOrList()
    {
        return array(
            'true creates IgnoreAllFilter' => array(true, 'Composer\Filter\PlatformRequirementFilter\IgnoreAllPlatformRequirementFilter'),
            'false creates IgnoreNothingFilter' => array(false, 'Composer\Filter\PlatformRequirementFilter\IgnoreNothingPlatformRequirementFilter'),
            'list creates IgnoreListFilter' => array(array('php', 'ext-json'), 'Composer\Filter\PlatformRequirementFilter\IgnoreListPlatformRequirementFilter'),
        );
    }

    public function testFromBoolThrowsExceptionIfTypeIsUnknown()
    {
        $this->setExpectedException('InvalidArgumentException', 'PlatformRequirementFilter: Unknown $boolOrList parameter NULL. Please report at https://github.com/composer/composer/issues/new.');

        PlatformRequirementFilter::fromBoolOrList(null);
    }

    public function testIgnoreAll()
    {
        $platformRequirementFilter = PlatformRequirementFilter::ignoreAll();

        $this->assertInstanceOf('Composer\Filter\PlatformRequirementFilter\IgnoreAllPlatformRequirementFilter', $platformRequirementFilter);
    }

    public function testIgnoreNothing()
    {
        $platformRequirementFilter = PlatformRequirementFilter::ignoreNothing();

        $this->assertInstanceOf('Composer\Filter\PlatformRequirementFilter\IgnoreNothingPlatformRequirementFilter', $platformRequirementFilter);
    }

    public function testIsAllIgnored()
    {
        $this->assertTrue(PlatformRequirementFilter::ignoreAll()->isAllIgnored());
        $this->assertFalse(PlatformRequirementFilter::ignoreNothing()->isAllIgnored());
    }

    public function testIsNothingIgnored()
    {
        $this->assertTrue(PlatformRequirementFilter::ignoreNothing()->isNothingIgnored());
        $this->assertFalse(PlatformRequirementFilter::ignoreAll()->isNothingIgnored());
    }
}
