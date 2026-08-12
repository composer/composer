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

namespace Composer\Package\Version;

use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Repository\PlatformRepository;

/**
 * @internal
 */
final class VersionAge
{
    public static function isOldEnough(PackageInterface $package, int $minimumAge, ?int $referenceTime = null): bool
    {
        if ($minimumAge < 0) {
            throw new \InvalidArgumentException('The minimum package age must be zero or greater.');
        }

        if ($minimumAge === 0 || $package instanceof RootPackageInterface || PlatformRepository::isPlatformPackage($package->getName())) {
            return true;
        }

        $releaseDate = $package->getReleaseDate();
        if ($releaseDate === null) {
            return false;
        }

        $referenceTime = $referenceTime ?? time();

        return ($referenceTime - (float) $releaseDate->getTimestamp()) >= (float) $minimumAge * 86400;
    }
}
