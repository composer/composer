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

use Composer\FilterList\FilterListEntry;
use Composer\FilterList\FilterListProviderConfig;
use Composer\Semver\Constraint\ConstraintInterface;

/**
 * Repositories that allow fetching filter list data
 *
 * @internal
 */
interface FilterListProviderInterface
{
    public function hasFilter(): bool;

    /**
     * @param array<string, ConstraintInterface> $packageConstraintMap
     * @return array{filter: array<string, list<FilterListEntry>>, config: FilterListProviderConfig}
     */
    public function getFilter(array $packageConstraintMap): array;
}
