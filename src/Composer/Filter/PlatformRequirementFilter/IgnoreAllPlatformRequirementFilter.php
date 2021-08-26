<?php

namespace Composer\Filter\PlatformRequirementFilter;

use Composer\Repository\PlatformRepository;

final class IgnoreAllPlatformRequirementFilter extends PlatformRequirementFilter
{
    /**
     * @param string $req
     * @return bool
     */
    public function isReqIgnored($req)
    {
        return PlatformRepository::isPlatformPackage($req);
    }
}
