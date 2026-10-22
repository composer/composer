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

use Composer\Filter\PackageFilter\CooldownRule;
use Composer\Policy\CooldownPolicyConfig;
use Composer\Package\AliasPackage;
use DateTimeImmutable;

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
        $this->unverifiedVersions = [];

        if (!$this->config->hasCooldown() || !$this->config->block) {
            return $pool;
        }

        // Locked packages kept by a partial update are not subject to the cooldown
        $candidates = [];
        foreach ($pool->getPackages() as $index => $package) {
            $target = $package instanceof AliasPackage ? $package->getAliasOf() : $package;
            if (!$request->isLockedPackage($package) && !$request->isLockedPackage($target)) {
                $candidates[$index] = $package;
            }
        }

        $rule = new CooldownRule($this->config, $this->now);
        $rejections = $rule->getRejections($candidates);
        $this->unverifiedVersions = $rule->getUnverifiedVersions();

        $packages = [];
        $policyRemovedVersions = $pool->getAllPolicyRemovedVersions();
        foreach ($pool->getPackages() as $index => $package) {
            if (isset($rejections[$index])) {
                Pool::recordPolicyRemoval($policyRemovedVersions, $package, $rejections[$index]);
                continue;
            }

            $packages[] = $package;
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
}
