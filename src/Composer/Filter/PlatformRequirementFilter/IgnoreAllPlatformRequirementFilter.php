<?php

namespace Composer\Filter\PlatformRequirementFilter;

use Composer\Repository\PlatformRepository;

final class IgnoreAllPlatformRequirementFilter implements PlatformRequirementFilterInterface
{
    /**
     * @param string $req
     * @return bool
     */
    public function isIgnored($req)
    {
        return PlatformRepository::isPlatformPackage($req);
    }
}
