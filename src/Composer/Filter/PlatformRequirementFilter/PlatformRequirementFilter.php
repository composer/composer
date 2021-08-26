<?php

namespace Composer\Filter\PlatformRequirementFilter;

abstract class PlatformRequirementFilter
{
    /**
     * @param string $req
     * @return bool
     */
    abstract public function isReqIgnored($req);

    /**
     * @param mixed $boolOrList
     *
     * @return PlatformRequirementFilter
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
     * @return PlatformRequirementFilter
     */
    public static function ignoreAll()
    {
        return new IgnoreAllPlatformRequirementFilter();
    }

    /**
     * @return PlatformRequirementFilter
     */
    public static function ignoreNothing()
    {
        return new IgnoreNothingPlatformRequirementFilter();
    }

    /**
     * @return bool
     */
    public function isAllIgnored()
    {
        return $this instanceof IgnoreAllPlatformRequirementFilter;
    }

    /**
     * @return bool
     */
    public function isNothingIgnored()
    {
        return $this instanceof IgnoreNothingPlatformRequirementFilter;
    }
}
