<?php

namespace Composer\Util\Exception\PackageHelper;

class NoMatchForConstraintWithPhpVersionException extends \InvalidArgumentException
{
    public static function given($name, $requiredVersion, $phpVersion)
    {
        return new self(sprintf(
            'Package %s at version %s has a PHP requirement incompatible with your PHP version (%s)',
            $name,
            $requiredVersion,
            $phpVersion
        ));
    }
}
