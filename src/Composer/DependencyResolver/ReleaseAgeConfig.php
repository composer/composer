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

use Composer\Config;
use Composer\Package\BasePackage;
use Composer\Pcre\Preg;
use Composer\Util\Platform;

/**
 * Configuration for the minimum release age feature
 *
 * @readonly
 * @internal
 */
class ReleaseAgeConfig
{
    /**
     * Minimum age in seconds, null means disabled
     * @var int|null
     */
    public $minimumReleaseAge;

    /**
     * Package patterns that are excluded from filtering
     * @var array<array{package: string, reason: string}>
     */
    public $exceptions;

    /**
     * @param int|null $minimumReleaseAge Minimum age in seconds, null means disabled
     * @param array<array{package: string, reason: string}> $exceptions Package patterns to exclude
     */
    public function __construct(?int $minimumReleaseAge, array $exceptions)
    {
        $this->minimumReleaseAge = $minimumReleaseAge;
        $this->exceptions = $exceptions;
    }

    /**
     * Check if the release age filtering is enabled
     */
    public function isEnabled(): bool
    {
        return $this->minimumReleaseAge !== null && $this->minimumReleaseAge > 0;
    }

    /**
     * Check if a package name matches any exception pattern
     */
    public function isPackageExcepted(string $packageName): bool
    {
        foreach ($this->exceptions as $exception) {
            $patternRegexp = BasePackage::packageNameToRegexp($exception['package']);
            if (Preg::isMatch($patternRegexp, $packageName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the reason for a package exception, or null if not excepted
     */
    public function getExceptionReason(string $packageName): ?string
    {
        foreach ($this->exceptions as $exception) {
            $patternRegexp = BasePackage::packageNameToRegexp($exception['package']);
            if (Preg::isMatch($patternRegexp, $packageName)) {
                return $exception['reason'];
            }
        }

        return null;
    }

    /**
     * Parse a duration string (e.g., "7 days", "24 hours") to seconds
     *
     * @param string|int|null $duration Duration string, integer seconds, or null
     * @return int|null Duration in seconds, or null if disabled
     */
    public static function parseDuration($duration): ?int
    {
        if ($duration === null || $duration === '' || $duration === 0 || $duration === '0') {
            return null;
        }

        if (is_int($duration)) {
            if ($duration < 0) {
                throw new \RuntimeException("Invalid minimum-release-age: duration cannot be negative ({$duration}).");
            }

            return $duration;
        }

        if (is_numeric($duration)) {
            $value = (int) $duration;
            if ($value < 0) {
                throw new \RuntimeException("Invalid minimum-release-age: duration cannot be negative ({$duration}).");
            }

            return $value;
        }

        // Parse strings like "7 days", "1 week", "24 hours"
        $timestamp = strtotime($duration, 0);
        if ($timestamp === false) {
            throw new \RuntimeException("Invalid minimum-release-age format: {$duration}. Use formats like '7 days', '24 hours', or an integer for seconds.");
        }

        if ($timestamp < 0) {
            throw new \RuntimeException("Invalid minimum-release-age: duration cannot be negative ({$duration}).");
        }

        return $timestamp;
    }

    /**
     * Create a ReleaseAgeConfig from Composer config
     */
    public static function fromConfig(Config $config): self
    {
        $releaseAgeConfig = $config->get('minimum-release-age');

        $minimumAge = null;
        $exceptions = [];

        if (is_array($releaseAgeConfig)) {
            $minimumAge = $releaseAgeConfig['minimum-age'] ?? null;
            $exceptions = self::parseExceptions($releaseAgeConfig['exceptions'] ?? []);
        }

        // Environment variable overrides minimum-age but preserves exceptions from config
        $envValue = Platform::getEnv('COMPOSER_MINIMUM_RELEASE_AGE');
        if ($envValue !== false && $envValue !== '') {
            $minimumAge = $envValue;
        }

        return new self(
            self::parseDuration($minimumAge),
            $exceptions
        );
    }

    /**
     * Parse exceptions configuration
     *
     * @param array<mixed> $exceptionsConfig
     * @return array<array{package: string, reason: string}>
     */
    private static function parseExceptions(array $exceptionsConfig): array
    {
        $exceptions = [];

        foreach ($exceptionsConfig as $exception) {
            if (is_array($exception) && isset($exception['package'])) {
                $exceptions[] = [
                    'package' => (string) $exception['package'],
                    'reason' => (string) ($exception['reason'] ?? ''),
                ];
            }
        }

        return $exceptions;
    }
}
