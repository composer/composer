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
     * Require-dev is ignored by default, you can enable the require-dev of the initial $requirer
     * packages by passing $includeRequireDev=true, but require-dev of transitive dependencies
     * are always ignored.
     *
     * @template T of PackageInterface
     * @param  array<T> $packages
     * @param  list<T> $bucket Do not pass this in, only used to avoid recursion with circular deps
     * @return list<T>
     */
    public static function filterRequiredPackages(array $packages, PackageInterface $requirer, bool $includeRequireDev = false, array $bucket = []): array
    {
        $requires = $requirer->getRequires();
        if ($includeRequireDev) {
            $requires = array_merge($requires, $requirer->getDevRequires());
        }

        foreach ($packages as $candidate) {
            foreach ($candidate->getNames() as $name) {
                if (isset($requires[$name])) {
                    if (!in_array($candidate, $bucket, true)) {
                        $bucket[] = $candidate;
                        $bucket = self::filterRequiredPackages($packages, $candidate, false, $bucket);
                    }
                    break;
                }
            }
        }

        return $bucket;
    }

    /**
     * Unwraps CompositeRepository, InstalledRepository and optionally FilterRepository to get a flat array of pure repository instances
     *
     * @return RepositoryInterface[]
     */
    public static function flattenRepositories(RepositoryInterface $repo, bool $unwrapFilterRepos = true): array
    {
        // unwrap filter repos
        if ($unwrapFilterRepos && $repo instanceof FilterRepository) {
            $repo = $repo->getRepository();
        }

        if (!$repo instanceof CompositeRepository) {
            return [$repo];
        }

        $repos = [];
        foreach ($repo->getRepositories() as $r) {
            foreach (self::flattenRepositories($r, $unwrapFilterRepos) as $r2) {
                $repos[] = $r2;
            }
        }

        return $repos;
    }
}
