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

use Composer\Package\PackageInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 *
 * @see RepositorySet for ways to work with sets of repos
 */
class RepositoryUtils
{
    /**
     * Find all of $packages which are required by $requirer, either directly or transitively
     *
     * Require-dev is ignored
     *
     * @template T of PackageInterface
     * @param  array<T> $packages
     * @param  array<T> $bucket Do not pass this in, only used to avoid recursion with circular deps
     * @return list<T>
     */
    public static function filterRequiredPackages(array $packages, PackageInterface $requirer, array $bucket = array()): array
    {
        $requires = $requirer->getRequires();

        foreach ($packages as $candidate) {
            foreach ($candidate->getNames() as $name) {
                if (isset($requires[$name])) {
                    if (!in_array($candidate, $bucket, true)) {
                        $bucket[] = $candidate;
                        $bucket = self::filterRequiredPackages($packages, $candidate, $bucket);
                    }
                    break;
                }
            }
        }

        return $bucket;
    }
}
