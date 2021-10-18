<?php

namespace Composer\Filter\PlatformRequirementFilter;

final class IgnoreNothingPlatformRequirementFilter implements PlatformRequirementFilterInterface
{
    /**
     * @param string $req
     * @return false
     */
    public function isReqIgnored($req)
    {
        return false;
    }
}
