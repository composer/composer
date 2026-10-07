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

namespace Composer\Repository;

use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Package\BasePackage;

/**
 * @internal
 */
interface PrefetchableRepositoryInterface
{
    /**
     * @param array<string, ConstraintInterface|null> $packageNameMap
     * @param array<key-of<BasePackage::STABILITIES>, BasePackage::STABILITY_*> $acceptableStabilities
     * @param array<string, BasePackage::STABILITY_*> $stabilityFlags
     * @param bool $initialize Whether ordered loading has reached this repository
     * @return string[]|null Names to withhold from lower-priority prefetching, or null when ownership is unknown
     */
    public function prefetchPackages(array $packageNameMap, array $acceptableStabilities = BasePackage::STABILITIES, array $stabilityFlags = [], bool $initialize = false): ?array;
}
