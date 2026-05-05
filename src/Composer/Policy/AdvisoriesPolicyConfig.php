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

/**
 * @internal
 * @final
 * @readonly
 */
class AdvisoriesPolicyConfig extends ListPolicyConfig
{
    public const NAME = 'advisories';

    /**
     * @var array<string, IgnoreIdRule>
     */
    public $ignoreId;

    /**
     * @var array<string, IgnoreSeverityRule>
     */
    public $ignoreSeverity;

    /**
     * @param array<string, list<IgnorePackageRule>> $ignore
     * @param array<string, IgnoreIdRule> $ignoreId
     * @param array<string, IgnoreSeverityRule> $ignoreSeverity
     * @param self::AUDIT_* $audit
     */
    public function __construct(
        bool $block,
        string $audit,
        array $ignore,
        array $ignoreId,
        array $ignoreSeverity
    ) {
        parent::__construct(
            self::NAME,
            $block,
            $audit,
             $ignore
        );
        $this->ignoreId = $ignoreId;
        $this->ignoreSeverity = $ignoreSeverity;
    }

    /**
     * Get ignore-id rules filtered for a specific operation (advisories only).
     *
     * @param 'block'|'audit' $operation
     * @return array<string, string|null>  Keyed by advisory/CVE ID => reason
     */
    public function getIgnoreIdForOperation(string $operation): array
    {
        $result = [];
        foreach ($this->ignoreId as $id => $rule) {
            if (($operation === 'block' && $rule->onBlock) || ($operation === 'audit' && $rule->onAudit)) {
                $result[$id] = $rule->reason;
            }
        }

        return $result;
    }

    /**
     * Get ignore-severity rules filtered for a specific operation (advisories only).
     *
     * @param 'block'|'audit' $operation
     * @return array<string, string|null>  Keyed by severity => reason
     */
    public function getIgnoreSeverityForOperation(string $operation): array
    {
        $result = [];
        foreach ($this->ignoreSeverity as $severity => $rule) {
            if (($operation === 'block' && $rule->onBlock) || ($operation === 'audit' && $rule->onAudit)) {
                $result[$severity] = $rule->reason;
            }
        }

        return $result;
    }

    public function withBlockingDisabled()
    {
        return new static(
            false,
            $this->audit,
            $this->ignore,
            $this->ignoreId,
            $this->ignoreSeverity
        );
    }

    /**
     * @param array<string, mixed> $policyConfig
     * @param array<string, mixed> $auditConfig
     */
    public static function fromRawConfig(array $policyConfig, array $auditConfig, VersionParser $parser): self
    {
        if (!isset($policyConfig['advisories']) && $auditConfig !== []) {
            $legacyIgnore = parent::parseLegacyAuditIgnore($auditConfig['ignore'] ?? [], $parser);

            return new self(
                $auditConfig['block-insecure'] ?? true,
                    self::AUDIT_FAIL,
                $legacyIgnore['packages'] ?? [],
                $legacyIgnore['ids'] ?? [],
                self::parseLegacySeverityWithApply($auditConfig['ignore-severity'] ?? [])
            );
        }

        $advisoryConfig = $policyConfig['advisories'] ?? [];
        if ($advisoryConfig === false) {
            return self::disabled();
        }

        if (!is_array($advisoryConfig)) {
            $advisoryConfig = [];
        }

        return new self(
            (bool) ($advisoryConfig['block'] ?? true),
            $advisoryConfig['audit'] ?? self::AUDIT_FAIL,
            IgnorePackageRule::parseIgnoreMap($advisoryConfig['ignore'] ?? [], $parser),
            IgnoreIdRule::parseIgnoreIdMap($advisoryConfig['ignore-id'] ?? []),
            IgnoreSeverityRule::parseIgnoreSeverityMap($advisoryConfig['ignore-severity'] ?? [])
        );
    }

    public static function disabled(): self
    {
        return new self(
            false,
            self::AUDIT_IGNORE,
            [],
            [],
            []
        );
    }

    /**
     * @param array<mixed> $config
     * @return array<string, IgnoreSeverityRule>
     */
    private static function parseLegacySeverityWithApply(array $config): array
    {
        $result = [];
        foreach ($config as $key => $value) {
            $severity = is_int($key) ? (string) $value : $key;
            $parsed = self::parseLegacySingleIgnore($key, $value);
            $result[$severity] = new IgnoreSeverityRule($severity, $parsed['reason'], $parsed['onBlock'], $parsed['onAudit']);
        }

        return $result;
    }
}
