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
use Composer\Package\BasePackage;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Repository\PlatformRepository;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Withholds package versions published more recently than the configured cooldown period.
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

    /** @var array<string, list<string>> Repository name => "name (version)" of versions judged without a repository-provided publication date */
    private $unverifiedVersions = [];

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
        $this->unverifiedVersions = [];

        $removed = [];
        $policyRemovedVersions = $pool->getAllPolicyRemovedVersions();
        /** @var array<string, array<string, true>> $withheldReleases */
        $withheldReleases = [];
        /** @var array<string, array<string, true>> $keptReleases */
        $keptReleases = [];
        /** @var list<BasePackage> $devCandidates */
        $devCandidates = [];

        foreach ($pool->getPackages() as $package) {
            // An alias shares the release date of the package it aliases but reports its own
            // stability, so decide on the aliased package to keep the pair consistent
            $target = $package instanceof AliasPackage ? $package->getAliasOf() : $package;

            // Skip filtering for packages that should always be allowed through:
            // 1. Root packages
            // 2. Platform packages (php, ext-*, lib-*, etc.)
            // 3. Locked packages kept by a partial update
            // 4. Ignored packages (matching configured policy.cooldown.ignore rules)
            if ($target instanceof RootPackageInterface
                || PlatformRepository::isPlatformPackage($target->getName())
                || $request->isLockedPackage($package)
                || $request->isLockedPackage($target)
                || $this->config->isIgnored($target, 'block')
            ) {
                if (!$target->isDev()) {
                    $keptReleases[$target->getName()][$target->getVersion()] = true;
                }
                continue;
            }

            // Dev versions are mutable and have no release of their own, so they are only
            // withheld when they would stand in for releases the cooldown removed, see below
            if ($target->isDev()) {
                $devCandidates[] = $package;
                continue;
            }

            // A Composer repository has to vouch for the publication date, see the policy config
            $this->config->assertPublishedTimeProvided($target);

            // Nothing vouches for the date of this version, keep track so the user learns the protection is weaker
            if ($target->getPublishedDate() === null && !$package instanceof AliasPackage && $target->getRepository() !== null) {
                $this->unverifiedVersions[$target->getRepository()->getRepoName()][] = $package->getPrettyName().' ('.$package->getPrettyVersion().')';
            }

            // 5. Packages without release date (conservative - don't block unverifiable)
            if ($this->effectiveDate($target) === null) {
                $keptReleases[$target->getName()][$target->getVersion()] = true;
                continue;
            }

            // Check if package is old enough
            $releaseDate = $this->effectiveDate($target);
            if (!$this->config->isWithinCooldown($releaseDate, $this->now)) {
                $keptReleases[$target->getName()][$target->getVersion()] = true;
                continue;
            }

            // Package is too new - filter it out and track for error messages
            $withheldReleases[$target->getName()][$target->getVersion()] = true;
            $removed[spl_object_id($package)] = true;
            Pool::recordPolicyRemoval($policyRemovedVersions, $package, PolicyRemovalReason::cooldown(
                $package->getName(),
                $package->getPrettyVersion(),
                $releaseDate->format(DateTimeInterface::ATOM),
                $this->config->formatTimeUntilAvailable($releaseDate, $this->now),
                $target->getPublishedDate() !== null ? 'published-time' : 'time'
            ));
        }

        // A dev branch has no waiting period, so it must not stand in for releases the cooldown
        // withheld. Gather what the graph requires of those packages to tell a requirement asking
        // for the branch itself apart from one that only reaches it because its releases are gone
        $branchesByName = [];
        foreach ($devCandidates as $package) {
            $name = ($package instanceof AliasPackage ? $package->getAliasOf() : $package)->getName();
            if (isset($withheldReleases[$name])) {
                $branchesByName[$name] = true;
            }
        }

        if (count($branchesByName) > 0) {
            /** @var array<string, list<ConstraintInterface>> $requirements */
            $requirements = [];
            foreach ($request->getRequires() as $name => $constraint) {
                if (isset($branchesByName[$name])) {
                    $requirements[$name][] = $constraint;
                }
            }
            foreach ($pool->getPackages() as $package) {
                // a version this filter removed cannot be installed, so nothing it requires can matter
                if (isset($removed[spl_object_id($package)]) || $package instanceof RootPackageInterface || PlatformRepository::isPlatformPackage($package->getName())) {
                    continue;
                }
                foreach ($package->getRequires() as $link) {
                    if (isset($branchesByName[$link->getTarget()])) {
                        $requirements[$link->getTarget()][] = $link->getConstraint();
                    }
                }
            }

            foreach ($devCandidates as $package) {
                $target = $package instanceof AliasPackage ? $package->getAliasOf() : $package;
                $name = $target->getName();
                if (!isset($branchesByName[$name])) {
                    continue;
                }

                $branchVersion = new Constraint('==', $target->getVersion());
                $askedForBranch = false;
                $neededByRequirement = false;
                foreach ($requirements[$name] ?? [] as $constraint) {
                    if (!$constraint->matches($branchVersion)) {
                        continue;
                    }
                    // a requirement matching no release at all asks for the branch itself
                    if (!$this->matchesAnyVersion($constraint, $withheldReleases[$name])) {
                        $askedForBranch = true;
                        break;
                    }
                    // one release the cooldown kept satisfies this requirement, so it does not need the branch
                    if (isset($keptReleases[$name]) && $this->matchesAnyVersion($constraint, $keptReleases[$name])) {
                        continue;
                    }
                    $neededByRequirement = true;
                }

                if ($askedForBranch || !$neededByRequirement) {
                    continue;
                }

                $branchDate = $this->effectiveDate($target);
                if ($branchDate === null || !$this->config->isWithinCooldown($branchDate, $this->now)) {
                    continue;
                }

                $removed[spl_object_id($package)] = true;
                Pool::recordPolicyRemoval($policyRemovedVersions, $package, PolicyRemovalReason::cooldown(
                    $package->getName(),
                    $package->getPrettyVersion(),
                    $branchDate->format(DateTimeInterface::ATOM),
                    $this->config->formatTimeUntilAvailable($branchDate, $this->now),
                    $target->getPublishedDate() !== null ? 'published-time' : 'time'
                ));
            }
        }

        $packages = [];
        foreach ($pool->getPackages() as $package) {
            if (!isset($removed[spl_object_id($package)])) {
                $packages[] = $package;
            }
        }

        return new Pool(
            $packages,
            $pool->getUnacceptableFixedOrLockedPackages(),
            $pool->getAllRemovedVersions(),
            $pool->getAllRemovedVersionsByPackage(),
            $policyRemovedVersions
        );
    }

    /**
     * Versions the last filter() run had to judge by the package-supplied time field, or by no date at
     * all, grouped by the name of the repository they came from
     *
     * @return array<string, list<string>>
     */
    public function getUnverifiedVersions(): array
    {
        return $this->unverifiedVersions;
    }

    /**
     * Resolve a package's effective date once per filter() run and reuse it across the
     * skip and cooldown checks.
     */
    private function effectiveDate(PackageInterface $package): ?DateTimeInterface
    {
        $key = spl_object_id($package);
        if (!array_key_exists($key, $this->effectiveDateCache)) {
            $this->effectiveDateCache[$key] = $this->config->getEffectiveDate($package);
        }

        return $this->effectiveDateCache[$key];
    }

    /**
     * @param array<string, true> $versions normalized versions
     */
    private function matchesAnyVersion(ConstraintInterface $constraint, array $versions): bool
    {
        foreach ($versions as $version => $unused) {
            if ($constraint->matches(new Constraint('==', (string) $version))) {
                return true;
            }
        }

        return false;
    }
}
