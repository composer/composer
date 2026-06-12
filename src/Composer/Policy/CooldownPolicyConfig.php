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

use Composer\Semver\VersionParser;
use Composer\Util\Platform;

/**
 * Configuration for the cooldown policy.
 *
 * A cooldown withholds package versions whose publication is more recent than
 * the configured `age` during update/require, to reduce exposure to a
 * compromised-maintainer release. On top of the shared list skeleton
 * (block/audit/ignore) it carries the cooldown duration in `age`.
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
     * Whether a cooldown duration is actually configured. Without one the
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
        // the block/audit/ignore settings from config.
        $envValue = Platform::getEnv('COMPOSER_POLICY_COOLDOWN_AGE');
        if ($envValue !== false && $envValue !== '') {
            $age = self::parseDuration($envValue);
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
     * Accepts an integer number of seconds, a numeric string, or a human
     * readable duration such as "7 days", "24 hours" or "1 week". null, an
     * empty string and 0 all mean "no cooldown".
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

        if (is_numeric($duration)) {
            $value = (int) $duration;
            if ($value < 0) {
                throw new \RuntimeException("Invalid policy.cooldown.age: duration cannot be negative ({$duration}).");
            }

            return $value;
        }

        // Parse strings like "7 days", "1 week", "24 hours"
        $timestamp = strtotime($duration, 0);
        if ($timestamp === false) {
            throw new \RuntimeException("Invalid policy.cooldown.age format: {$duration}. Use formats like '7 days', '24 hours', or an integer for seconds.");
        }

        if ($timestamp < 0) {
            throw new \RuntimeException("Invalid policy.cooldown.age: duration cannot be negative ({$duration}).");
        }

        return $timestamp;
    }
}
