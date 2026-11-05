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

namespace Composer\Filter\PackageFilter;

use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Policy\CooldownPolicyConfig;
use Composer\Repository\PlatformRepository;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Rejects versions that have not yet cleared the cooldown period
 *
 * @internal
 * @final
 */
class CooldownRule implements RejectionRuleInterface
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

    public function getRejections(array $packages): array
    {
        if (!$this->config->hasCooldown() || !$this->config->block) {
            return [];
        }

        $rejections = [];
        foreach ($packages as $key => $package) {
            // An alias shares the release date of the package it aliases but reports its own
            // stability, so decide on the aliased package to keep the pair consistent
            $target = $package instanceof AliasPackage ? $package->getAliasOf() : $package;

            // Dev versions are mutable and have no stable release date concept
            if ($target instanceof RootPackageInterface
                || PlatformRepository::isPlatformPackage($target->getName())
                || $target->isDev()
                || $this->config->isIgnored($target, 'block')
            ) {
                continue;
            }

            // A Composer repository has to vouch for the publication date, see the policy config
            $this->config->assertPublishedTimeProvided($target);

            // Nothing vouches for the date of this version, keep track so the user learns the protection is weaker
            if ($target->getPublishedDate() === null && !$package instanceof AliasPackage && $target->getRepository() !== null) {
                $this->unverifiedVersions[$target->getRepository()->getRepoName()][] = $package->getPrettyName().' ('.$package->getPrettyVersion().')';
            }

            // Versions without a release date cannot be verified, so they are not blocked
            $releaseDate = $this->effectiveDate($target);
            if ($releaseDate === null || !$this->config->isWithinCooldown($releaseDate, $this->now)) {
                continue;
            }

            $rejections[$key] = RejectionReason::cooldown(
                $package->getName(),
                $package->getPrettyVersion(),
                $releaseDate->format(DateTimeInterface::ATOM),
                $this->config->formatTimeUntilAvailable($releaseDate, $this->now),
                $target->getPublishedDate() !== null ? 'published-time' : 'time'
            );
        }

        return $rejections;
    }

    /**
     * Versions checked so far that had to be judged by the package-supplied time field, or by no date at
     * all, grouped by the name of the repository they came from
     *
     * @return array<string, list<string>>
     */
    public function getUnverifiedVersions(): array
    {
        return $this->unverifiedVersions;
    }

    /**
     * Resolve a package's effective date once and reuse it across the checks
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
