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
    /** @var array<int, array<string, string>> Map of package object id => removed normalized versions => removed pretty version */
    protected $removedVersionsByPackage = [];
    /** @var array<string, array<string, PolicyRemovalReason>> Map of package name => normalized version => why a policy removed it */
    private $policyRemovedVersions = [];

    /**
     * @param BasePackage[] $packages
     * @param BasePackage[] $unacceptableFixedOrLockedPackages
     * @param array<string, array<string, string>> $removedVersions
     * @param array<int, array<string, string>> $removedVersionsByPackage
     * @param array<string, array<string, PolicyRemovalReason>> $policyRemovedVersions
     */
    public function __construct(array $packages = [], array $unacceptableFixedOrLockedPackages = [], array $removedVersions = [], array $removedVersionsByPackage = [], array $policyRemovedVersions = [])
    {
        $this->versionParser = new VersionParser;
        $this->setPackages($packages);
        $this->unacceptableFixedOrLockedPackages = $unacceptableFixedOrLockedPackages;
        $this->removedVersions = $removedVersions;
        $this->removedVersionsByPackage = $removedVersionsByPackage;
        $this->policyRemovedVersions = $policyRemovedVersions;
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
    public function getRemovedVersionsByPackage(int $objectId): array
    {
        if (!isset($this->removedVersionsByPackage[$objectId])) {
            return [];
        }

        return $this->removedVersionsByPackage[$objectId];
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function getAllRemovedVersionsByPackage(): array
    {
        return $this->removedVersionsByPackage;
    }

    /**
     * Versions of a package that dependency policies removed from the pool, with the reason for each
     *
     * @return array<string, PolicyRemovalReason> normalized version => reason, in ascending version order
     */
    public function getPolicyRemovedVersions(string $packageName, ?ConstraintInterface $constraint = null): array
    {
        $result = [];
        foreach ($this->policyRemovedVersions[$packageName] ?? [] as $version => $reason) {
            if ($constraint === null || $constraint->matches(new Constraint('==', $version))) {
                $result[$version] = $reason;
            }
        }
        uksort($result, static function (string $a, string $b): int {
            return version_compare($a, $b);
        });

        return $result;
    }

    public function getPolicyRemovalReason(string $packageName, string $version): ?PolicyRemovalReason
    {
        return $this->policyRemovedVersions[$packageName][$version] ?? null;
    }

    /**
     * Records a removal under every name of the package, but a replacer never hides the removal of the package itself
     *
     * @internal
     * @param array<string, array<string, PolicyRemovalReason>> $policyRemovedVersions
     */
    public static function recordPolicyRemoval(array &$policyRemovedVersions, BasePackage $package, PolicyRemovalReason $reason): void
    {
        foreach ($package->getNames(false) as $name) {
            $existing = $policyRemovedVersions[$name][$package->getVersion()] ?? null;
            if ($existing === null || ($existing->getPackageName() !== $name && $package->getName() === $name)) {
                $policyRemovedVersions[$name][$package->getVersion()] = $reason;
            }
        }
    }

    /**
     * @return array<string, array<string, PolicyRemovalReason>>
     */
    public function getAllPolicyRemovedVersions(): array
    {
        return $this->policyRemovedVersions;
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
