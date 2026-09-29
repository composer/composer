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

use Composer\Policy\CooldownPolicyConfig;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Repository\PlatformRepository;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Withholds package versions published more recently than the configured cooldown age.
 *
 * Unlike the list-based policies (advisories/malware/custom lists) this filter is purely
 * time-based and has no remote sources to fetch, so it is wired as a dedicated pool filter
 * rather than through FilterListPoolFilter.
 *
 * @internal
 */
class CooldownPoolFilter
{
    /** @var CooldownPolicyConfig */
    private $config;

    /** @var DateTimeImmutable */
    private $now;

    /** @var array<int, ?DateTimeInterface> */
    private $effectiveDateCache = [];

    public function __construct(CooldownPolicyConfig $config, ?DateTimeImmutable $now = null)
    {
        $this->config = $config;
        $this->now = $now ?? new DateTimeImmutable();
    }

    /**
     * Filter packages from the pool that have not yet cleared the cooldown
     */
    public function filter(Pool $pool, Request $request): Pool
    {
        if (!$this->config->hasCooldown() || !$this->config->block) {
            return $pool;
        }

        $this->effectiveDateCache = [];

        $packages = [];
        $cooldownRemovedVersions = [];

        foreach ($pool->getPackages() as $package) {
            // An alias shares the release date of the package it aliases but reports its own
            // stability, so decide on the aliased package to keep the pair consistent
            $target = $package instanceof AliasPackage ? $package->getAliasOf() : $package;

            // Skip filtering for packages that should always be allowed through:
            // 1. Root packages
            // 2. Platform packages (php, ext-*, lib-*, etc.)
            // 3. Locked packages kept by a partial update
            // 4. Dev versions (mutable, no stable release date concept)
            // 5. Ignored packages (matching configured policy.cooldown.ignore rules)
            // 6. Packages without release date (conservative - don't block unverifiable)
            if ($target instanceof RootPackageInterface
                || PlatformRepository::isPlatformPackage($target->getName())
                || $request->isLockedPackage($package)
                || $request->isLockedPackage($target)
                || $target->isDev()
                || $this->config->isIgnored($target, 'block')
                || $this->effectiveDate($target) === null
            ) {
                $packages[] = $package;
                continue;
            }

            // Check if package is old enough
            $releaseDate = $this->effectiveDate($target);
            if (!$this->config->isWithinCooldown($releaseDate, $this->now)) {
                $packages[] = $package;
                continue;
            }

            // Package is too new - filter it out and track for error messages
            foreach ($package->getNames(false) as $packageName) {
                $cooldownRemovedVersions[$packageName][$package->getVersion()] = [
                    'name' => $package->getName(),
                    'prettyVersion' => $package->getPrettyVersion(),
                    'releaseDate' => $releaseDate->format(DateTimeInterface::ATOM),
                    'availableIn' => $this->config->formatTimeUntilAvailable($releaseDate, $this->now),
                    'source' => $target->getPublishedDate() !== null ? 'published-time' : 'time',
                ];
            }
        }

        return new Pool(
            $packages,
            $pool->getUnacceptableFixedOrLockedPackages(),
            $pool->getAllRemovedVersions(),
            $pool->getAllRemovedVersionsByPackage(),
            $pool->getAllSecurityRemovedPackageVersions(),
            $pool->getAllAbandonedRemovedPackageVersions(),
            $pool->getAllFilterListRemovedPackageVersions(),
            $cooldownRemovedVersions
        );
    }

    /**
     * Resolve a package's effective date once per filter() run and reuse it across the
     * skip and age checks.
     */
    private function effectiveDate(PackageInterface $package): ?DateTimeInterface
    {
        $key = spl_object_id($package);
        if (!array_key_exists($key, $this->effectiveDateCache)) {
            $this->effectiveDateCache[$key] = $this->config->getEffectiveDate($package);
        }

        return $this->effectiveDateCache[$key];
    }
}
