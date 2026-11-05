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

namespace Composer\Filter\PackageFilter;

use Composer\Package\BasePackage;

/**
 * A rule that rejects package versions which must not be installed, e.g. because of a dependency policy
 *
 * @internal
 */
interface RejectionRuleInterface
{
    /**
     * Rules receive all packages at once so they can fetch the data they need in bulk
     *
     * @template TKey of array-key
     * @param array<TKey, BasePackage> $packages
     * @return array<TKey, RejectionReason> the keys of the rejected packages => why each was rejected
     */
    public function getRejections(array $packages): array;
}
