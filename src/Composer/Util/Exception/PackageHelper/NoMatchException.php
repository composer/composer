<?php

namespace Composer\Util\Exception\PackageHelper;

class NoMatchException extends \InvalidArgumentException
{
    public static function given($name, $minimumStability)
    {
        return new self(sprintf(
            'Could not find a matching version of package %s. Check the package spelling, your version constraint and that the package is available in a stability which matches your minimum-stability (%s).',
            $name,
            $minimumStability
        ));
    }
}
