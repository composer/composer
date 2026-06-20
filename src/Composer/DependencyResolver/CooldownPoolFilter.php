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
use Composer\Policy\CooldownPolicyConfig;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Repository\PlatformRepository;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Semver\Constraint\MultiConstraint;
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

    /** @var array<string, array<PartialSecurityAdvisory|SecurityAdvisory>> */
    private $securityAdvisories = [];

    /** @var array<string, ?DateTimeInterface> */
    private $effectiveDateCache = [];

    public function __construct(CooldownPolicyConfig $config, ?DateTimeImmutable $now = null)
    {
        $this->config = $config;
        $this->now = $now ?? new DateTimeImmutable();
    }

    /**
     * Set security advisories to enable security fix detection
     *
     * When set, packages that are security fixes (released after an advisory
     * and not affected by it) will bypass the cooldown requirement.
     *
     * @param array<string, array<PartialSecurityAdvisory|SecurityAdvisory>> $advisories
     */
    public function setSecurityAdvisories(array $advisories): void
    {
        $this->securityAdvisories = $advisories;
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

        // First pass: find oldest security fix per package per constraint part
        // This ensures we only allow the oldest (most vetted) security fix to bypass
        // while still supporting disjunctive constraints (e.g., ^3.0 || ^4.0)
        $oldestSecurityFixDate = $this->findOldestSecurityFixPerConstraint($pool, $request);

        $packages = [];
        $cooldownRemovedVersions = [];

        foreach ($pool->getPackages() as $package) {
            // Skip filtering for packages that should always be allowed through:
            // 1. Root packages
            // 2. Platform packages (php, ext-*, lib-*, etc.)
            // 3. Already locked packages (installed)
            // 4. Dev versions (mutable, no stable release date concept)
            // 5. Ignored packages (matching configured policy.cooldown.ignore rules)
            // 6. Packages without release date (conservative - don't block unverifiable)
            if ($package instanceof RootPackageInterface
                || PlatformRepository::isPlatformPackage($package->getName())
                || $request->isLockedPackage($package)
                || $package->isDev()
                || $this->config->isIgnored($package, 'block')
                || $this->effectiveDate($package) === null
            ) {
                $packages[] = $package;
                continue;
            }

            // Check if package is old enough
            $releaseDate = $this->effectiveDate($package);
            if (!$this->config->isWithinCooldown($releaseDate, $this->now)) {
                $packages[] = $package;
                continue;
            }

            // Check if this is the oldest security fix for its constraint part
            // Only the oldest security fix per constraint part bypasses the cooldown
            if ($this->isOldestSecurityFixForConstraint($package, $oldestSecurityFixDate, $request)) {
                $packages[] = $package;
                continue;
            }

            // Package is too new - filter it out and track for error messages
            foreach ($package->getNames(false) as $packageName) {
                $cooldownRemovedVersions[$packageName][$package->getVersion()] = [
                    'prettyVersion' => $package->getPrettyVersion(),
                    'releaseDate' => $releaseDate->format(DateTimeInterface::ATOM),
                    'availableIn' => $this->config->formatTimeUntilAvailable($releaseDate, $this->now),
                    'source' => $package->getPublishedDate() !== null ? 'published-time' : 'time',
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
     * skip/age checks and the security-fix passes.
     */
    private function effectiveDate(PackageInterface $package): ?DateTimeInterface
    {
        $key = spl_object_hash($package);
        if (!array_key_exists($key, $this->effectiveDateCache)) {
            $this->effectiveDateCache[$key] = $this->config->getEffectiveDate($package);
        }

        return $this->effectiveDateCache[$key];
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
            $releaseDate = $this->effectiveDate($package);
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
        $releaseDate = $this->effectiveDate($package);
        if ($releaseDate === null) {
            return false;
        }

        $constraintParts = $this->getConstraintParts($name, $request->getRequires());
        $packageConstraint = new Constraint('==', $package->getVersion());

        // Check all matching constraint parts - package bypasses if it's the oldest for ANY part.
        // Compare instants with the same precision used when selecting the oldest in
        // findOldestSecurityFixPerConstraint() (the '<' there). The stored value is the per-key
        // minimum, so "<= minimum" is true only for the oldest version; a sibling released in the
        // same whole second but a fraction later is correctly excluded.
        foreach ($constraintParts as $index => $part) {
            if ($part->matches($packageConstraint)) {
                $key = $name . ':' . $index;
                if (isset($oldestSecurityFixDate[$key]) && $releaseDate <= $oldestSecurityFixDate[$key]) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if a package version is a security fix that should bypass the cooldown requirement
     *
     * A version is considered a security fix if:
     * 1. The package has known security advisories
     * 2. This version is NOT affected by any of those advisories
     * 3. For full SecurityAdvisory: released after advisory and within 2x the cooldown age after advisory
     * 4. For PartialSecurityAdvisory: released within 2x the cooldown age from now (fallback when reportedAt unavailable)
     */
    private function isSecurityFix(PackageInterface $package): bool
    {
        $releaseDate = $this->effectiveDate($package);
        if ($releaseDate === null) {
            return false;
        }

        $age = $this->config->age;

        // Should not happen as isSecurityFix is only called when the filter is active
        if ($age === null) {
            return false;
        }

        $packageConstraint = new Constraint(Constraint::STR_OP_EQ, $package->getVersion());
        $bypassWindow = $age * 2;

        // Match advisories under any of the package's names (including replaced names),
        // mirroring SecurityAdvisoryPoolFilter::getMatchingAdvisories().
        foreach ($package->getNames(false) as $packageName) {
            if (!isset($this->securityAdvisories[$packageName])) {
                continue;
            }

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

                    // Only bypass if within 2x the cooldown age after the advisory
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
                    // Allow bypass only if version was released recently (within 2x the cooldown age from now)
                    // This prevents old advisories from granting perpetual bypass
                    $windowStart = $this->now->modify("-{$bypassWindow} seconds");

                    if ($releaseDate >= $windowStart) {
                        // Version is recent and not affected by advisory - likely a security fix
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
