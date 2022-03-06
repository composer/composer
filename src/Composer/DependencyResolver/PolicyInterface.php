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

use Composer\Package\PackageInterface;
use Composer\Semver\Constraint\Constraint;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
interface PolicyInterface
{
    /**
     * @param  string $operator
     * @return bool
     *
     * @phpstan-param Constraint::STR_OP_* $operator
     */
    public function versionCompare(PackageInterface $a, PackageInterface $b, string $operator): bool;

    /**
     * @param  int[]   $literals
     * @param  null|string $requiredPackage
     * @return int[]
     */
    public function selectPreferredPackages(Pool $pool, array $literals, ?string $requiredPackage = null): array;
}
