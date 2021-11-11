<?php

namespace Composer\Filter\PlatformRequirementFilter;

interface PlatformRequirementFilterInterface
{
    /**
     * @param string $req
     * @return bool
     */
    public function isIgnored($req);
}
