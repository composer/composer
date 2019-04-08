<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Util;

use Composer\DependencyResolver\Pool;
use Composer\Package\BasePackage;
use Composer\Package\Version\VersionSelector;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Util\Exception\PackageHelper\NoMatchException;
use Composer\Util\Exception\PackageHelper\NoMatchForConstraintWithPhpVersionException;
use Composer\Util\Exception\PackageHelper\NoMatchForConstraintException;
use Composer\Util\Exception\PackageHelper\NoMatchForMinimumStabilityException;
use Composer\Util\Exception\PackageHelper\NoMatchForPhpVersionException;
use Composer\Util\Exception\PackageHelper\NoMatchWithSuggestionsException;

class PackageHelper
{
    /** @var Pool[] */
    private $pools = array();
    /** @var RepositoryInterface */
    private $repository;

    public function __construct(RepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Given a package name, find up to $limit packages that match given package name.
     *
     * @param string $package
     * @param int $limit
     *
     * @return array[string]
     */
    public function findSimilar($package, $limit = 5)
    {
        try {
            $results = $this->repository->search($package);
        } catch (\Exception $e) {
            return array();
        }

        $similarPackages = array();

        foreach ($results as $result) {
            $similarPackages[$result['name']] = levenshtein($package, $result['name']);
        }

        asort($similarPackages);

        return array_keys(array_slice($similarPackages, 0, $limit));
    }

    /**
     * Given a package name, determine the best version to use in the require key.
     *
     * @param string $name
     * @param string $preferredStability
     * @param string $minimumStability
     * @param string|null $phpVersion
     * @param string|null $constraint
     * @param bool $ignorePlatformRequirements
     *
     * @return array
     *
     * @throws NoMatchForConstraintWithPhpVersionException
     * @throws NoMatchForConstraintException
     * @throws NoMatchForPhpVersionException
     * @throws NoMatchForMinimumStabilityException
     * @throws NoMatchWithSuggestionsException
     * @throws NoMatchException
     */
    public function findBestVersionAndNameForPackage($name, $preferredStability, $minimumStability, $phpVersion = null, $constraint = null, $ignorePlatformRequirements = false)
    {
        $stabilityFlags = array();
        if ($constraint && preg_match('{^[^,\s]*?@('.implode('|', array_keys(BasePackage::$stabilities)).')$}i', $constraint, $match)) {
            $stabilityFlags[$name] = BasePackage::$stabilities[$match[1]];
        }

        $versionSelector = new VersionSelector($this->getPool($minimumStability, $stabilityFlags));
        $package = $versionSelector->findBestCandidate($name, $constraint, $phpVersion, $preferredStability);

        if (!$package) {
            // platform packages can not be found in the pool in versions other than the ones that the local platform
            // has, so if platform requirements are ignored we just take the user's word for it
            if ($ignorePlatformRequirements && preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $name)) {
                return array($name, $constraint ?: '*');
            }

            // if we were given a php version and constraint, check if we can find packages if we ignore the php version
            if ($phpVersion && $constraint && $versionSelector->findBestCandidate($name, $constraint, null, $preferredStability)) {
                throw NoMatchForConstraintWithPhpVersionException::given($name, $constraint, $phpVersion);
            }

            // if we were given a php version and constraint, check if we can find packages if we ignore the constraint
            if ($phpVersion && $constraint && $versionSelector->findBestCandidate($name, null, $phpVersion, $preferredStability)) {
                throw NoMatchForConstraintException::given($name, $constraint);
            }

            // if we were only given a constraint, check if we can find packages if we ignore the constraint
            if ($phpVersion === null && $constraint && $versionSelector->findBestCandidate($name, null, null, $preferredStability)) {
                throw NoMatchForConstraintException::given($name, $constraint);
            }

            // if we were only given a php version, check if we can find packages if we ignore the php version
            if ($phpVersion && $constraint === null && $versionSelector->findBestCandidate($name, null, null, $preferredStability)) {
                throw NoMatchForPhpVersionException::given($name, $phpVersion);
            }

            // Check for similar names/typos
            $similar = $this->findSimilar($name);

            if ($similar) {
                // Check whether the minimum stability was the problem but the package exists
                if ($constraint === null && in_array($name, $similar, true)) {
                    throw NoMatchForMinimumStabilityException::given($name, $minimumStability);
                }

                // We found packages with similar names
                throw NoMatchWithSuggestionsException::given($name, $similar);
            }

            throw NoMatchException::given($name, $minimumStability);
        }

        return array($package->getPrettyName(), $versionSelector->findRecommendedRequireVersion($package));
    }

    /**
     * @param string $minimumStability
     * @param array $stabilityFlags
     *
     * @return Pool
     */
    private function getPool($minimumStability, array $stabilityFlags)
    {
        if (!isset($this->pools[$minimumStability])) {
            $this->pools[$minimumStability] = $pool = new Pool($minimumStability, $stabilityFlags);
            $pool->addRepository($this->repository);
        }

        return $this->pools[$minimumStability];
    }
}
