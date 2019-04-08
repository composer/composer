<?php

namespace Composer\Util\Exception\PackageHelper;

class NoMatchWithSuggestionsException extends \InvalidArgumentException
{
    public static function given($name, array $similar)
    {
        return new self(sprintf(
            "Could not find package %s.\n\nDid you mean " . (count($similar) > 1 ? 'one of these' : 'this') . "?\n    %s",
            $name,
            implode("\n    ", $similar)
        ));
    }
}
