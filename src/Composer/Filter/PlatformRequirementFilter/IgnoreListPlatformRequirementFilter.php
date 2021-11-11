<?php

namespace Composer\Filter\PlatformRequirementFilter;

use Composer\Repository\PlatformRepository;

final class IgnoreListPlatformRequirementFilter implements PlatformRequirementFilterInterface
{
    /**
     * @var string[]
     */
    private $reqList;

    /**
     * @param string[] $reqList
     */
    public function __construct(array $reqList)
    {
        $this->reqList = $reqList;
    }

    /**
     * @param string $req
     * @return bool
     */
    public function isIgnored($req)
    {
        if (!PlatformRepository::isPlatformPackage($req)) {
            return false;
        }

        return in_array($req, $this->reqList, true);
    }
}
