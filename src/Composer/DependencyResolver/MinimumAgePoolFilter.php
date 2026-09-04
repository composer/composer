<?php declare(strict_types=1);

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\DependencyResolver;

use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\Version\VersionAge;

/**
 * @internal
 */
final class MinimumAgePoolFilter
{
    /** @var int */
    private $minimumAge;

    /** @var int */
    private $referenceTime;

    public function __construct(int $minimumAge, ?int $referenceTime = null)
    {
        if ($minimumAge < 0) {
            throw new \InvalidArgumentException('The minimum package age must be zero or greater.');
        }

        $this->minimumAge = $minimumAge;
        $this->referenceTime = $referenceTime ?? time();
    }

    public function filter(Pool $pool, Request $request): Pool
    {
        $packages = [];
        foreach ($pool->getPackages() as $package) {
            if ($this->isFixedOrLocked($package, $request) || VersionAge::isOldEnough($package, $this->minimumAge, $this->referenceTime)) {
                $packages[] = $package;
            }
        }

        return new Pool($packages, $pool->getUnacceptableFixedOrLockedPackages(), $pool->getAllRemovedVersions(), $pool->getAllRemovedVersionsByPackage(), $pool->getAllSecurityRemovedPackageVersions(), $pool->getAllAbandonedRemovedPackageVersions(), $pool->getAllFilterListRemovedPackageVersions());
    }

    private function isFixedOrLocked(BasePackage $package, Request $request): bool
    {
        while (true) {
            if ($request->isFixedPackage($package) || $request->isLockedPackage($package)) {
                return true;
            }

            if (!$package instanceof AliasPackage) {
                return false;
            }

            $package = $package->getAliasOf();
        }
    }
}
