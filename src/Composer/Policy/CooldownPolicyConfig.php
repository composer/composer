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

namespace Composer\Policy;

use Composer\Package\PackageInterface;
use Composer\Pcre\Preg;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\VersionParser;
use Composer\Util\Platform;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Configuration for the cooldown policy.
 *
 * A cooldown withholds package versions whose publication is more recent than
 * the configured `age` during update/require, to reduce exposure to a
 * compromised-maintainer release. On top of the shared list skeleton
 * (block/audit/ignore), it carries the cooldown duration in `age`.
 *
 * @internal
 * @final
 * @readonly
 */
class CooldownPolicyConfig extends ListPolicyConfig
{
    public const NAME = 'cooldown';

    /**
     * Cooldown duration in seconds. null means no cooldown is configured (feature inactive).
     *
     * @var int|null
     */
    public $age;

    /**
     * @param array<string, list<IgnorePackageRule>> $ignore
     * @param self::AUDIT_* $audit
     */
    public function __construct(
        bool $block,
        string $audit,
        array $ignore,
        ?int $age
    ) {
        parent::__construct(
            self::NAME,
            $block,
            $audit,
            $ignore
        );

        $this->age = $age;
    }

    /**
     * Whether a cooldown duration is actually configured. Without one, the
     * policy is inert regardless of the `block` flag.
     */
    public function hasCooldown(): bool
    {
        return $this->age !== null && $this->age > 0;
    }

    /**
     * @param self::BLOCK_SCOPE_* $blockScope
     */
    public function shouldBlock(string $blockScope): bool
    {
        if (!$this->hasCooldown()) {
            return false;
        }

        return parent::shouldBlock($blockScope);
    }

    /**
     * The timestamp the cooldown is measured against.
     *
     * Prefers the server-set publication date (which the package author cannot
     * influence) and falls back to the author-controlled release date when the
     * repository does not provide one.
     */
    public function getEffectiveDate(PackageInterface $package): ?DateTimeInterface
    {
        return $package->getPublishedDate() ?? $package->getReleaseDate();
    }

    /**
     * Whether a package version matches a configured policy.cooldown.ignore rule
     * for the given operation.
     *
     * @param 'block'|'audit' $operation
     */
    public function isIgnored(PackageInterface $package, string $operation): bool
    {
        $ignore = $this->getIgnoreForOperation($operation);
        if (count($ignore) === 0) {
            return false;
        }

        $packageConstraint = new Constraint('==', $package->getVersion());
        foreach ($ignore as $rules) {
            foreach ($rules as $rule) {
                foreach ($package->getNames(false) as $name) {
                    if (Preg::isMatch($rule->packageNameRegex, $name) && $rule->constraint->matches($packageConstraint)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Whether the given effective date is still inside the cooldown window
     * relative to $now (i.e. too recent to be allowed).
     */
    public function isWithinCooldown(DateTimeInterface $effectiveDate, DateTimeInterface $now): bool
    {
        if (!$this->hasCooldown()) {
            return false;
        }

        $cutoffDate = (new DateTimeImmutable($now->format(DateTimeInterface::ATOM)))
            ->modify("-{$this->age} seconds");

        return $effectiveDate > $cutoffDate;
    }

    /**
     * Format the time until a package version will clear the cooldown.
     */
    public function formatTimeUntilAvailable(DateTimeInterface $effectiveDate, DateTimeImmutable $now): string
    {
        $availableAt = (new DateTimeImmutable($effectiveDate->format(DateTimeInterface::ATOM)))
            ->modify("+{$this->age} seconds");
        $diff = $now->diff($availableAt);
        $days = (int) $diff->days;

        $parts = [];
        if ($days > 0) {
            $parts[] = $days . ' day' . ($days > 1 ? 's' : '');
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

    public function withBlockingDisabled()
    {
        return new static(
            false,
            $this->audit,
            $this->ignore,
            $this->age
        );
    }

    public function withAudit(string $audit)
    {
        return new static(
            $this->block,
            $audit,
            $this->ignore,
            $this->age
        );
    }

    /**
     * @param array<string, mixed> $policyConfig
     */
    public static function fromRawConfig(array $policyConfig, VersionParser $parser): self
    {
        $cooldownConfig = $policyConfig['cooldown'] ?? [];
        if ($cooldownConfig === false) {
            return self::disabled();
        }

        if (!is_array($cooldownConfig)) {
            $cooldownConfig = [];
        }

        $age = self::parseDuration($cooldownConfig['age'] ?? null);

        // Environment variable overrides the configured duration but preserves
        // the block/audit/ignore settings from config
        $envValue = Platform::getEnv('COMPOSER_POLICY_COOLDOWN_AGE');
        if ($envValue !== false && $envValue !== '') {
            try {
                $age = self::parseDuration($envValue);
            } catch (\RuntimeException $e) {
                throw new \RuntimeException(
                    "Invalid value for COMPOSER_POLICY_COOLDOWN_AGE: {$envValue}. "
                    . "Use formats like '7 days', '24 hours', or an integer number of seconds.",
                    0,
                    $e
                );
            }
        }

        return new self(
            (bool) ($cooldownConfig['block'] ?? true),
            $cooldownConfig['audit'] ?? self::AUDIT_IGNORE,
            IgnorePackageRule::parseIgnoreMap($cooldownConfig['ignore'] ?? [], $parser),
            $age
        );
    }

    public static function disabled(): self
    {
        return new self(
            false,
            self::AUDIT_IGNORE,
            [],
            null
        );
    }

    /**
     * Parse a duration into seconds.
     *
     * Accepts an integer number of seconds, an integer string, or an explicit
     * unit duration such as "7 days", "24 hours", "30 minutes", "90 seconds" or
     * "1 week" (the unit may be singular or plural). null, an empty string, and 0
     * all mean "no cooldown".
     *
     * Relative phrases such as "tomorrow" or "next week" are intentionally
     * rejected: they would resolve to surprising values (and some to 0, which
     * silently disables the policy), so a typo throws instead of distorting the
     * cooldown.
     *
     * @param string|int|null $duration
     * @throws \RuntimeException on a negative or unparseable duration
     */
    public static function parseDuration($duration): ?int
    {
        if ($duration === null || $duration === '' || $duration === 0 || $duration === '0') {
            return null;
        }

        if (is_int($duration)) {
            if ($duration < 0) {
                throw new \RuntimeException("Invalid policy.cooldown.age: duration cannot be negative ({$duration}).");
            }

            return $duration;
        }

        $trimmed = trim($duration);

        // A plain integer is interpreted as a number of seconds.
        if (Preg::isMatch('/^\d+$/D', $trimmed)) {
            return (int) $trimmed;
        }

        // Reject any other numeric value (negative or fractional) with a clear message.
        if (is_numeric($trimmed)) {
            if ((float) $trimmed < 0) {
                throw new \RuntimeException("Invalid policy.cooldown.age: duration cannot be negative ({$duration}).");
            }

            throw new \RuntimeException("Invalid policy.cooldown.age format: {$duration}. Use an integer number of seconds or a duration like '7 days', '24 hours', '30 minutes' or '1 week'.");
        }

        // Otherwise only explicit unit durations are accepted.
        static $units = [
            'second' => 1,
            'minute' => 60,
            'hour' => 3600,
            'day' => 86400,
            'week' => 604800,
        ];

        if (Preg::isMatchStrictGroups('/^(\d+)\s*(second|minute|hour|day|week)s?$/i', $trimmed, $matches)) {
            return (int) $matches[1] * $units[strtolower($matches[2])];
        }

        throw new \RuntimeException("Invalid policy.cooldown.age format: {$duration}. Use an integer number of seconds or a duration like '7 days', '24 hours', '30 minutes' or '1 week'.");
    }
}
