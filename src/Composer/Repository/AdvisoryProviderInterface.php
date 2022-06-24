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
use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Advisory\SecurityAdvisory;

/**
 * Repositories that allow fetching security advisory data
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @internal
 */
interface AdvisoryProviderInterface
{
    public function hasSecurityAdvisories(): bool;

    /**
     * @param array<string, ConstraintInterface> $packageConstraintMap Map of package name to constraint (can be MatchAllConstraint to fetch all advisories)
     * @return ($allowPartialAdvisories is true ? array{namesFound: string[], advisories: array<string, array<PartialSecurityAdvisory|SecurityAdvisory>>} : array{namesFound: string[], advisories: array<string, array<SecurityAdvisory>>})
     */
    public function getSecurityAdvisories(array $packageConstraintMap, bool $allowPartialAdvisories = false): array;
}
