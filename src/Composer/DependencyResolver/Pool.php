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
use Composer\Package\BasePackage;
use Composer\Package\Version\VersionParser;
use Composer\Semver\CompilingMatcher;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\Constraint;

/**
 * A package pool contains all packages for dependency resolution
 *
 * @author Nils Adermann <naderman@naderman.de>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Pool implements \Countable
{
    /** @var BasePackage[] */
    protected $packages = [];
    /** @var array<string, BasePackage[]> */
    protected $packageByName = [];
    /** @var VersionParser */
    protected $versionParser;
    /** @var array<string, array<string, BasePackage[]>> */
    protected $providerCache = [];
    /** @var BasePackage[] */
    protected $unacceptableFixedOrLockedPackages;
    /** @var array<string, array<string, string>> Map of package name => normalized version => pretty version */
    protected $removedVersions = [];
    /** @var array<string, array<string, string>> Map of package object hash => removed normalized versions => removed pretty version */
    protected $removedVersionsByPackage = [];
    /** @var array<string, array<string, array<SecurityAdvisory|PartialSecurityAdvisory>>> Map of package name => normalized version => security advisories */
    private $securityRemovedVersions = [];
    /** @var array<string, array<string, string>> Map of package name => normalized version => pretty version */
    private $abandonedRemovedVersions = [];

    /**
     * @param BasePackage[] $packages
     * @param BasePackage[] $unacceptableFixedOrLockedPackages
     * @param array<string, array<string, string>> $removedVersions
     * @param array<string, array<string, string>> $removedVersionsByPackage
     * @param array<string, array<string, array<SecurityAdvisory|PartialSecurityAdvisory>>> $securityRemovedVersions
     * @param array<string, array<string, string>> $abandonedRemovedVersions
 */
    public function __construct(array $packages = [], array $unacceptableFixedOrLockedPackages = [], array $removedVersions = [], array $removedVersionsByPackage = [], array $securityRemovedVersions = [], array $abandonedRemovedVersions = [])
    {
        $this->versionParser = new VersionParser;
        $this->setPackages($packages);
        $this->unacceptableFixedOrLockedPackages = $unacceptableFixedOrLockedPackages;
        $this->removedVersions = $removedVersions;
        $this->removedVersionsByPackage = $removedVersionsByPackage;
        $this->securityRemovedVersions = $securityRemovedVersions;
        $this->abandonedRemovedVersions = $abandonedRemovedVersions;
    }

    /**
     * @return array<string, string>
     */
    public function getRemovedVersions(string $name, ConstraintInterface $constraint): array
    {
        if (!isset($this->removedVersions[$name])) {
            return [];
        }

        $result = [];
        foreach ($this->removedVersions[$name] as $version => $prettyVersion) {
            if ($constraint->matches(new Constraint('==', $version))) {
                $result[$version] = $prettyVersion;
            }
        }

        return $result;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function getAllRemovedVersions(): array
    {
        return $this->removedVersions;
    }

    /**
     * @return array<string, string>
     */
    public function getRemovedVersionsByPackage(string $objectHash): array
    {
        if (!isset($this->removedVersionsByPackage[$objectHash])) {
            return [];
        }

        return $this->removedVersionsByPackage[$objectHash];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function getAllRemovedVersionsByPackage(): array
    {
        return $this->removedVersionsByPackage;
    }

    public function isSecurityRemovedPackageVersion(string $packageName, ?ConstraintInterface $constraint): bool
    {
        foreach ($this->securityRemovedVersions[$packageName] ?? [] as $version => $packageWithSecurityAdvisories) {
            if ($constraint !== null && $constraint->matches(new Constraint('==', $version))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    public function getSecurityAdvisoryIdentifiersForPackageVersion(string $packageName, ?ConstraintInterface $constraint): array
    {
        foreach ($this->securityRemovedVersions[$packageName] ?? [] as $version => $packageWithSecurityAdvisories) {
            if ($constraint !== null && $constraint->matches(new Constraint('==', $version))) {
                return array_map(function ($advisory) {
                    return $advisory->advisoryId;
                }, $packageWithSecurityAdvisories);
            }
        }

        return [];
    }

    public function isAbandonedRemovedPackageVersion(string $packageName, ?ConstraintInterface $constraint): bool
    {
        foreach ($this->abandonedRemovedVersions[$packageName] ?? [] as $version => $prettyVersion) {
            if ($constraint !== null && $constraint->matches(new Constraint('==', $version))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array<string, array<SecurityAdvisory|PartialSecurityAdvisory>>>
     */
    public function getAllSecurityRemovedPackageVersions(): array
    {
        return $this->securityRemovedVersions;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function getAllAbandonedRemovedPackageVersions(): array
    {
        return $this->abandonedRemovedVersions;
    }

    /**
     * @param BasePackage[] $packages
     */
    private function setPackages(array $packages): void
    {
        $id = 1;

        foreach ($packages as $package) {
            $this->packages[] = $package;

            $package->id = $id++;

            foreach ($package->getNames() as $provided) {
                $this->packageByName[$provided][] = $package;
            }
        }
    }

    /**
     * @return BasePackage[]
     */
    public function getPackages(): array
    {
        return $this->packages;
    }

    /**
     * Retrieves the package object for a given package id.
     */
    public function packageById(int $id): BasePackage
    {
        return $this->packages[$id - 1];
    }

    /**
     * Returns how many packages have been loaded into the pool
     */
    public function count(): int
    {
        return \count($this->packages);
    }

    /**
     * Searches all packages providing the given package name and match the constraint
     *
     * @param string $name The package name to be searched for
     * @param ?ConstraintInterface $constraint A constraint that all returned
     *                                         packages must match or null to return all
     * @return BasePackage[] A set of packages
     */
    public function whatProvides(string $name, ?ConstraintInterface $constraint = null): array
    {
        $key = (string) $constraint;
        if (isset($this->providerCache[$name][$key])) {
            return $this->providerCache[$name][$key];
        }

        return $this->providerCache[$name][$key] = $this->computeWhatProvides($name, $constraint);
    }

    /**
     * @param  string               $name       The package name to be searched for
     * @param  ?ConstraintInterface $constraint A constraint that all returned
     *                                          packages must match or null to return all
     * @return BasePackage[]
     */
    private function computeWhatProvides(string $name, ?ConstraintInterface $constraint = null): array
    {
        if (!isset($this->packageByName[$name])) {
            return [];
        }

        $matches = [];

        foreach ($this->packageByName[$name] as $candidate) {
            if ($this->match($candidate, $name, $constraint)) {
                $matches[] = $candidate;
            }
        }

        return $matches;
    }

    public function literalToPackage(int $literal): BasePackage
    {
        $packageId = abs($literal);

        return $this->packageById($packageId);
    }

    /**
     * @param array<int, BasePackage> $installedMap
     */
    public function literalToPrettyString(int $literal, array $installedMap): string
    {
        $package = $this->literalToPackage($literal);

        if (isset($installedMap[$package->id])) {
            $prefix = ($literal > 0 ? 'keep' : 'remove');
        } else {
            $prefix = ($literal > 0 ? 'install' : 'don\'t install');
        }

        return $prefix.' '.$package->getPrettyString();
    }

    /**
     * Checks if the package matches the given constraint directly or through
     * provided or replaced packages
     *
     * @param  string              $name       Name of the package to be matched
     */
    public function match(BasePackage $candidate, string $name, ?ConstraintInterface $constraint = null): bool
    {
        $candidateName = $candidate->getName();
        $candidateVersion = $candidate->getVersion();

        if ($candidateName === $name) {
            return $constraint === null || CompilingMatcher::match($constraint, Constraint::OP_EQ, $candidateVersion);
        }

        $provides = $candidate->getProvides();
        $replaces = $candidate->getReplaces();

        // aliases create multiple replaces/provides for one target so they can not use the shortcut below
        if (isset($replaces[0]) || isset($provides[0])) {
            foreach ($provides as $link) {
                if ($link->getTarget() === $name && ($constraint === null || $constraint->matches($link->getConstraint()))) {
                    return true;
                }
            }

            foreach ($replaces as $link) {
                if ($link->getTarget() === $name && ($constraint === null || $constraint->matches($link->getConstraint()))) {
                    return true;
                }
            }

            return false;
        }

        if (isset($provides[$name]) && ($constraint === null || $constraint->matches($provides[$name]->getConstraint()))) {
            return true;
        }

        if (isset($replaces[$name]) && ($constraint === null || $constraint->matches($replaces[$name]->getConstraint()))) {
            return true;
        }

        return false;
    }

    public function isUnacceptableFixedOrLockedPackage(BasePackage $package): bool
    {
        return \in_array($package, $this->unacceptableFixedOrLockedPackages, true);
    }

    /**
     * @return BasePackage[]
     */
    public function getUnacceptableFixedOrLockedPackages(): array
    {
        return $this->unacceptableFixedOrLockedPackages;
    }

    public function __toString(): string
    {
        $str = "Pool:\n";

        foreach ($this->packages as $package) {
            $str .= '- '.str_pad((string) $package->id, 6, ' ', STR_PAD_LEFT).': '.$package->getName()."\n";
        }

        return $str;
    }
}
