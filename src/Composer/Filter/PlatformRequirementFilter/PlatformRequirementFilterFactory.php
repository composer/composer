<?php declare(strict_types=1);

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Filter\PlatformRequirementFilter;

final class PlatformRequirementFilterFactory
{
    /**
     * @param mixed $boolOrList
     */
    public static function fromBoolOrList($boolOrList): PlatformRequirementFilterInterface
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

    public static function ignoreAll(): PlatformRequirementFilterInterface
    {
        return new IgnoreAllPlatformRequirementFilter();
    }

    public static function ignoreNothing(): PlatformRequirementFilterInterface
    {
        return new IgnoreNothingPlatformRequirementFilter();
    }
}
