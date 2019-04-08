<?php

namespace Composer\Util\Exception\PackageHelper;

class NoMatchForConstraintException extends \InvalidArgumentException
{
    public static function given($name, $requiredVersion)
    {
        return new self(sprintf(
            'Could not find package %s in a version matching %s',
            $name,
            $requiredVersion
        ));
    }
}
