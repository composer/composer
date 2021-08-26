<?php

namespace Composer\Filter\PlatformRequirementFilter;

final class IgnoreNothingPlatformRequirementFilter extends PlatformRequirementFilter
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
