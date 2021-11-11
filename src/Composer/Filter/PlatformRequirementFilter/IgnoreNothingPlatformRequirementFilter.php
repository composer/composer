<?php

namespace Composer\Filter\PlatformRequirementFilter;

final class IgnoreNothingPlatformRequirementFilter implements PlatformRequirementFilterInterface
{
    /**
     * @param string $req
     * @return false
     */
    public function isIgnored($req)
    {
        return false;
    }
}
