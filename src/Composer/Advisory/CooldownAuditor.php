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

namespace Composer\Advisory;

use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Policy\CooldownPolicyConfig;
use Composer\Repository\PlatformRepository;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Reports installed/locked package versions whose publication is still within the
 * configured cooldown age, for `composer audit`.
 *
 * This is the audit-time counterpart to {@see \Composer\DependencyResolver\CooldownPoolFilter}.
 * Unlike the block path it operates on a flat package list with no resolver request, so the
 * security-fix bypass does not apply — audit surfaces every version within the cooldown.
 *
 * @internal
 * @final
 */
class CooldownAuditor
{
    /** @var DateTimeImmutable */
    private $now;

    public function __construct(?DateTimeImmutable $now = null)
    {
        $this->now = $now ?? new DateTimeImmutable();
    }

    /**
     * @param PackageInterface[] $packages
     * @return array<string, array{prettyVersion: string, releaseDate: string, availableIn: string, source: string}>
     *         Map of package name => cooldown info, for versions still within the cooldown
     */
    public function collect(array $packages, CooldownPolicyConfig $cooldown): array
    {
        if (!$cooldown->hasCooldown()) {
            return [];
        }

        $result = [];
        foreach ($packages as $package) {
            // Mirror the block path's skips: root, platform, dev, ignored, and
            // versions without a verifiable publication date.
            if ($package instanceof RootPackageInterface
                || PlatformRepository::isPlatformPackage($package->getName())
                || $package->isDev()
                || $cooldown->isIgnored($package, 'audit')
            ) {
                continue;
            }

            $releaseDate = $cooldown->getEffectiveDate($package);
            if ($releaseDate === null || !$cooldown->isWithinCooldown($releaseDate, $this->now)) {
                continue;
            }

            $result[$package->getName()] = [
                'prettyVersion' => $package->getPrettyVersion(),
                'releaseDate' => $releaseDate->format(DateTimeInterface::ATOM),
                'availableIn' => $cooldown->formatTimeUntilAvailable($releaseDate, $this->now),
                'source' => $package->getPublishedDate() !== null ? 'published-time' : 'time',
            ];
        }

        return $result;
    }
}
