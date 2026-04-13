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

use Composer\Package\BasePackage;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Semver\VersionParser;

/**
 * A unified ignore rule for policy lists.
 *
 * Supports:
 * - package name (with wildcard)
 * - optional version constraint
 * - on-block/on-audit scoping
 *
 * @internal
 * @final
 * @readonly
 */
class IgnorePackageRule
{
    /** @var string Package name pattern (may contain wildcards) */
    public $packageName;

    /** @var ConstraintInterface */
    public $constraint;

    /** @var string|null */
    public $reason;

    /** @var bool */
    public $onBlock;

    /** @var bool */
    public $onAudit;

    /** @var non-empty-string Regex for matching package names */
    public $packageNameRegex;

    public function __construct(
        string $packageName,
        ConstraintInterface $constraint,
        ?string $reason = null,
        bool $onBlock = true,
        bool $onAudit = true
    ) {
        $this->packageName = $packageName;
        $this->constraint = $constraint;
        $this->reason = $reason;
        $this->onBlock = $onBlock;
        $this->onAudit = $onAudit;
        $this->packageNameRegex = BasePackage::packageNameToRegexp($packageName);
    }

    /**
     * Parse an ignore map from config into IgnoreRule objects.
     *
     * Supports:
     * - "vendor/pkg": "reason"
     * - "vendor/pkg": {"constraint": "^2.0", "on-block": false, "reason": "..."}
     * - "vendor/pkg": [{"constraint": "^1.0", ...}, {"constraint": "^3.0", ...}]
     * - "vendor/pkg": null
     *
     * @param array<mixed> $config
     * @return array<string, list<self>>  Keyed by package name pattern
     */
    public static function parseIgnoreMap(array $config, ?VersionParser $parser = null): array
    {
        if ($parser === null) {
            $parser = new VersionParser();
        }

        $rules = [];

        foreach ($config as $key => $value) {
            // "vendor/pkg": null
            if ($value === null) {
                $rules[$key][] = new self($key, new MatchAllConstraint());
                continue;
            }

            // Simple string reason: "vendor/pkg": "reason"
            if (is_string($value) && !is_int($key)) {
                $rules[$key][] = new self($key, new MatchAllConstraint(), $value);
                continue;
            }

            // Numeric key with string value: ["vendor/pkg"]
            if (is_int($key) && is_string($value)) {
                $rules[$value][] = new self($value, new MatchAllConstraint());
                continue;
            }

            // Array of rule objects: "vendor/pkg": [{...}, {...}]
            if (is_array($value) && !is_int($key) && isset($value[0])) {
                foreach ($value as $ruleConfig) {
                    if (is_array($ruleConfig)) {
                        $rules[$key][] = self::fromRuleObject($key, $ruleConfig, $parser);
                    }
                }
                continue;
            }

            // Single rule object: "vendor/pkg": {"constraint": "...", ...}
            if (is_array($value) && !is_int($key)) {
                $rules[$key][] = self::fromRuleObject($key, $value, $parser);
                continue;
            }
        }

        return $rules;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function fromRuleObject(string $packageName, array $config, VersionParser $parser): self
    {
        $constraint = isset($config['constraint'])
            ? $parser->parseConstraints((string) $config['constraint'])
            : new MatchAllConstraint();

        return new self(
            $packageName,
            $constraint,
            $config['reason'] ?? null,
            $config['on-block'] ?? true,
            $config['on-audit'] ?? true
        );
    }

    /**
     * Filter a list of IgnoreRule maps to only include rules for the given operation.
     *
     * @param array<string, list<self>> $rules
     * @param 'block'|'audit' $operation
     * @return array<string, list<self>>
     */
    public static function filterByOperation(array $rules, string $operation): array
    {
        $filtered = [];
        foreach ($rules as $name => $ruleList) {
            foreach ($ruleList as $rule) {
                if (($operation === 'block' && $rule->onBlock) || ($operation === 'audit' && $rule->onAudit)) {
                    $filtered[$name][] = $rule;
                }
            }
        }

        return $filtered;
    }
}
