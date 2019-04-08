<?php

namespace Composer\Util\Exception\PackageHelper;

class NoMatchForMinimumStabilityException extends \InvalidArgumentException
{
    public static function given($name, $minimumStability)
    {
        return new self(sprintf(
            'Could not find a version of package %s matching your minimum-stability (%s). Require it with an explicit version constraint allowing its desired stability.',
            $name,
            $minimumStability
        ));
    }
}
