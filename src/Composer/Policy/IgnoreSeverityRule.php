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

/**
 * Ignore rule for a severity level (low, medium, high, critical).
 *
 * @internal
 * @final
 * @readonly
 */
class IgnoreSeverityRule
{
    /** @var string */
    public $severity;

    /** @var string|null */
    public $reason;

    /** @var bool */
    public $onBlock;

    /** @var bool */
    public $onAudit;

    public function __construct(string $severity, ?string $reason = null, bool $onBlock = true, bool $onAudit = true)
    {
        $this->severity = $severity;
        $this->reason = $reason;
        $this->onBlock = $onBlock;
        $this->onAudit = $onAudit;
    }

    /**
     * Parse an ignore-severity map from config.
     *
     * Supports:
     * - "low": "reason"
     * - "low": {"on-block": false, "reason": "..."}
     * - "low": null
     * - ["low", "medium"]
     *
     * @param array<mixed> $config
     * @return array<string, self>
     */
    public static function parseIgnoreSeverityMap(array $config): array
    {
        $rules = [];
        foreach ($config as $key => $value) {
            if (is_int($key) && is_string($value)) {
                $rules[$value] = new self($value);
                continue;
            }

            if ($value === null) {
                $rules[$key] = new self($key);
                continue;
            }

            if (is_string($value)) {
                $rules[$key] = new self($key, $value);
                continue;
            }

            if (is_array($value)) {
                $rules[$key] = new self(
                    $key,
                    $value['reason'] ?? null,
                    $value['on-block'] ?? true,
                    $value['on-audit'] ?? true
                );
                continue;
            }
        }

        return $rules;
    }
}
