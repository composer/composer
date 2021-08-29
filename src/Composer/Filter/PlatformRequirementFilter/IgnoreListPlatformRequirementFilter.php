<?php

namespace Composer\Filter\PlatformRequirementFilter;

use Composer\Package\BasePackage;
use Composer\Repository\PlatformRepository;

final class IgnoreListPlatformRequirementFilter implements PlatformRequirementFilterInterface
{
    /**
     * @var string
     */
    private $regexp;

    /**
     * @param string[] $reqList
     */
    public function __construct(array $reqList)
    {
        $this->regexp = BasePackage::packageNamesToRegexp($reqList);
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

        return 1 === preg_match($this->regexp, $req);
    }
}
