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

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Advisory\SecurityAdvisory;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Semver\Constraint\MultiConstraint;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Filters packages from the pool that are newer than the minimum release age
 *
 * @internal
 */
class ReleaseAgePoolFilter
{
    /** @var ReleaseAgeConfig */
    private $config;

    /** @var DateTimeImmutable */
    private $now;

    /** @var array<string, array<PartialSecurityAdvisory|SecurityAdvisory>> */
    private $securityAdvisories = [];

    public function __construct(ReleaseAgeConfig $config, ?DateTimeImmutable $now = null)
    {
        $this->config = $config;
        $this->now = $now ?? new DateTimeImmutable();
    }

    /**
     * Set security advisories to enable security fix detection
     *
     * When set, packages that are security fixes (released after an advisory
     * and not affected by it) will bypass the minimum release age requirement.
     *
     * @param array<string, array<PartialSecurityAdvisory|SecurityAdvisory>> $advisories
     */
    public function setSecurityAdvisories(array $advisories): void
    {
        $this->securityAdvisories = $advisories;
    }

    /**
     * Filter packages from the pool that don't meet the minimum release age requirement
     *
     * @param array<RepositoryInterface> $repositories
     */
    public function filter(Pool $pool, array $repositories, Request $request): Pool
    {
        if (!$this->config->isEnabled()) {
            return $pool;
        }

        $minimumAge = $this->config->minimumReleaseAge;
        $cutoffDate = $this->now->modify("-{$minimumAge} seconds");

        // First pass: find oldest security fix per package per constraint part
        // This ensures we only allow the oldest (most vetted) security fix to bypass
        // while still supporting disjunctive constraints (e.g., ^3.0 || ^4.0)
        $oldestSecurityFixDate = $this->findOldestSecurityFixPerConstraint($pool, $request);

        $packages = [];
        $releaseAgeRemovedVersions = [];

        foreach ($pool->getPackages() as $package) {
            // Skip filtering for packages that should always be allowed through:
            // 1. Root packages
            // 2. Platform packages (php, ext-*, lib-*, etc.)
            // 3. Already locked packages (installed)
            // 4. Dev versions (mutable, no stable release date concept)
            // 5. Excepted packages (matching configured patterns)
            // 6. Packages without release date (conservative - don't block unverifiable)
            if ($package instanceof RootPackageInterface
                || PlatformRepository::isPlatformPackage($package->getName())
                || $request->isLockedPackage($package)
                || $package->isDev()
                || $this->config->isPackageExcepted($package->getName())
                || $package->getReleaseDate() === null
            ) {
                $packages[] = $package;
                continue;
            }

            // Check if package is old enough
            $releaseDate = $package->getReleaseDate();
            if ($releaseDate <= $cutoffDate) {
                $packages[] = $package;
                continue;
            }

            // Check if this is the oldest security fix for its constraint part
            // Only the oldest security fix per constraint part bypasses the release age
            if ($this->isOldestSecurityFixForConstraint($package, $oldestSecurityFixDate, $request)) {
                $packages[] = $package;
                continue;
            }

            // Package is too new - filter it out and track for error messages
            foreach ($package->getNames(false) as $packageName) {
                $releaseAgeRemovedVersions[$packageName][$package->getVersion()] = [
                    'prettyVersion' => $package->getPrettyVersion(),
                    'releaseDate' => $releaseDate->format(DateTimeInterface::ATOM),
                    'availableIn' => $this->formatTimeUntilAvailable($releaseDate),
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
            $releaseAgeRemovedVersions
        );
    }

    /**
     * Find the oldest security fix release date per package per constraint part
     *
     * @return array<string, DateTimeInterface> Map of "packageName:constraintIndex" => oldest release date
     */
    private function findOldestSecurityFixPerConstraint(Pool $pool, Request $request): array
    {
        $oldestSecurityFixDate = [];
        $requires = $request->getRequires();

        foreach ($pool->getPackages() as $package) {
            if (!$this->isSecurityFix($package)) {
                continue;
            }

            $name = $package->getName();
            $releaseDate = $package->getReleaseDate();
            if ($releaseDate === null) {
                continue;
            }

            // Determine which constraint part(s) this version matches
            // A version may match multiple parts (e.g., 3.6.3 matches both ^3.5.0 and ^3.6.0)
            // We track the oldest for each part separately
            $constraintParts = $this->getConstraintParts($name, $requires);
            $packageConstraint = new Constraint('==', $package->getVersion());

            foreach ($constraintParts as $index => $part) {
                if ($part->matches($packageConstraint)) {
                    $key = $name . ':' . $index;
                    if (!isset($oldestSecurityFixDate[$key]) || $releaseDate < $oldestSecurityFixDate[$key]) {
                        $oldestSecurityFixDate[$key] = $releaseDate;
                    }
                    // Don't break - version may match multiple constraint parts
                }
            }
        }

        return $oldestSecurityFixDate;
    }

    /**
     * Get constraint parts for a package (breaks apart disjunctive constraints)
     *
     * @param array<string, ConstraintInterface> $requires
     * @return ConstraintInterface[]
     */
    private function getConstraintParts(string $packageName, array $requires): array
    {
        if (!isset($requires[$packageName])) {
            // No constraint in requires - treat as single group (index 0)
            return [new MatchAllConstraint()];
        }

        $constraint = $requires[$packageName];

        // If disjunctive MultiConstraint, return its parts
        if ($constraint instanceof MultiConstraint && $constraint->isDisjunctive()) {
            return $constraint->getConstraints();
        }

        // Otherwise treat as single constraint
        return [$constraint];
    }

    /**
     * Check if a package is the oldest security fix for any of its matching constraint parts
     *
     * @param array<string, DateTimeInterface> $oldestSecurityFixDate
     */
    private function isOldestSecurityFixForConstraint(
        PackageInterface $package,
        array $oldestSecurityFixDate,
        Request $request
    ): bool {
        if (!$this->isSecurityFix($package)) {
            return false;
        }

        $name = $package->getName();
        $releaseDate = $package->getReleaseDate();
        if ($releaseDate === null) {
            return false;
        }

        $constraintParts = $this->getConstraintParts($name, $request->getRequires());
        $packageConstraint = new Constraint('==', $package->getVersion());

        // Check all matching constraint parts - package bypasses if it's the oldest for ANY part
        foreach ($constraintParts as $index => $part) {
            if ($part->matches($packageConstraint)) {
                $key = $name . ':' . $index;
                if (isset($oldestSecurityFixDate[$key]) && $releaseDate === $oldestSecurityFixDate[$key]) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Format the time until a package version will be available
     */
    private function formatTimeUntilAvailable(DateTimeInterface $releaseDate): string
    {
        $availableAt = (new \DateTimeImmutable($releaseDate->format(\DateTimeInterface::ATOM)))
            ->modify("+{$this->config->minimumReleaseAge} seconds");
        $diff = $this->now->diff($availableAt);

        $parts = [];
        if ($diff->d > 0) {
            $parts[] = $diff->d . ' day' . ($diff->d > 1 ? 's' : '');
        }
        if ($diff->h > 0) {
            $parts[] = $diff->h . ' hour' . ($diff->h > 1 ? 's' : '');
        }
        if (count($parts) === 0 && $diff->i > 0) {
            $parts[] = $diff->i . ' minute' . ($diff->i > 1 ? 's' : '');
        }

        $result = implode(' ', $parts);

        return $result !== '' ? $result : 'less than a minute';
    }

    /**
     * Check if a package version is a security fix that should bypass release age requirement
     *
     * A version is considered a security fix if:
     * 1. The package has known security advisories
     * 2. This version is NOT affected by any of those advisories
     * 3. For full SecurityAdvisory: released after advisory and within 2x minimum age after advisory
     * 4. For PartialSecurityAdvisory: released within 2x minimum age from now (fallback when reportedAt unavailable)
     */
    private function isSecurityFix(PackageInterface $package): bool
    {
        $packageName = $package->getName();
        $releaseDate = $package->getReleaseDate();

        // Need both release date and advisories to determine if it's a security fix
        if ($releaseDate === null || !isset($this->securityAdvisories[$packageName])) {
            return false;
        }

        $packageConstraint = new Constraint(Constraint::STR_OP_EQ, $package->getVersion());
        $minimumAge = $this->config->minimumReleaseAge;

        // Should not happen as isSecurityFix is only called when filter is enabled
        if ($minimumAge === null) {
            return false;
        }

        $bypassWindow = $minimumAge * 2;

        foreach ($this->securityAdvisories[$packageName] as $advisory) {
            // Skip if this version is affected by the advisory (it's vulnerable, not a fix)
            if ($advisory->affectedVersions->matches($packageConstraint)) {
                continue;
            }

            // Full SecurityAdvisory with reportedAt: use advisory-relative timing
            if ($advisory instanceof SecurityAdvisory) {
                $advisoryDate = $advisory->reportedAt;

                // Only bypass if released AFTER the advisory was reported
                if ($releaseDate <= $advisoryDate) {
                    continue;
                }

                // Only bypass if within 2x the minimum release age after the advisory
                // This prevents old advisories from granting perpetual bypass
                $windowEnd = (new DateTimeImmutable($advisoryDate->format(DateTimeInterface::ATOM)))
                    ->modify("+{$bypassWindow} seconds");

                if ($releaseDate <= $windowEnd) {
                    // Package was released after advisory but within bypass window
                    // and is not affected - it's likely a security fix
                    return true;
                }
            } else {
                // PartialSecurityAdvisory without reportedAt: use now-relative timing
                // Allow bypass only if version was released recently (within 2x minimum age from now)
                // This prevents old advisories from granting perpetual bypass
                $windowStart = $this->now->modify("-{$bypassWindow} seconds");

                if ($releaseDate >= $windowStart) {
                    // Version is recent and not affected by advisory - likely a security fix
                    return true;
                }
            }
        }

        return false;
    }
}
