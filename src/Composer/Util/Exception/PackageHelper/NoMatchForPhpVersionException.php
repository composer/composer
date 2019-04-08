<?php

namespace Composer\Util\Exception\PackageHelper;

class NoMatchForPhpVersionException extends \InvalidArgumentException
{
    public static function given($name, $phpVersion)
    {
        return new self(sprintf(
            'Could not find package %s in any version matching your PHP version (%s)',
            $name,
            $phpVersion
        ));
    }
}
