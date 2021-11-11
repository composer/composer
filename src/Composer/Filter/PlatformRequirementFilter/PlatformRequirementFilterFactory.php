<?php

namespace Composer\Filter\PlatformRequirementFilter;

final class PlatformRequirementFilterFactory
{
    /**
     * @param mixed $boolOrList
     *
     * @return PlatformRequirementFilterInterface
     */
    public static function fromBoolOrList($boolOrList)
    {
        if (is_bool($boolOrList)) {
            return $boolOrList ? self::ignoreAll() : self::ignoreNothing();
        }

        if (is_array($boolOrList)) {
            return new IgnoreListPlatformRequirementFilter($boolOrList);
        }

        throw new \InvalidArgumentException(
            sprintf(
                'PlatformRequirementFilter: Unknown $boolOrList parameter %s. Please report at https://github.com/composer/composer/issues/new.',
                gettype($boolOrList)
            )
        );
    }

    /**
     * @return PlatformRequirementFilterInterface
     */
    public static function ignoreAll()
    {
        return new IgnoreAllPlatformRequirementFilter();
    }

    /**
     * @return PlatformRequirementFilterInterface
     */
    public static function ignoreNothing()
    {
        return new IgnoreNothingPlatformRequirementFilter();
    }
}
