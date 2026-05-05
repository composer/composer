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

use Composer\Config;
use Composer\Policy\IgnoreUnreachable;
use Composer\Policy\ListPolicyConfig;
use Composer\Policy\PolicyConfig;

/**
 * @readonly
 * @internal
 */
class AuditConfig
{
    /**
     * @var bool Whether to run audit
     */
    public $audit;

    /**
     * @var Auditor::FORMAT_*
     */
    public $auditFormat;

    /**
     * @var Auditor::ABANDONED_*
     */
    public $auditAbandoned;

    /**
     * @var Auditor::FILTERED_*
     */
    public $auditFiltered;

    /**
     * @var bool Should insecure versions be blocked during a composer update/required command
     */
    public $blockInsecure;

    /**
     * @var bool Should abandoned packages be blocked during a composer update/required command
     */
    public $blockAbandoned;

    /**
     * @var IgnoreUnreachable Should repositories that are unreachable or return a non-200 status code be ignored.
     */
    public $ignoreUnreachable;

    /**
     * @var array<string, string|null> List of advisory IDs to ignore during auditing => reason for ignoring
     */
    public $ignoreListForAudit;

    /**
     * @var array<string, string|null> List of advisory IDs to ignore during blocking
     */
    public $ignoreListForBlocking;

    /**
     * @var array<string, string|null> List of severities to ignore during auditing
     */
    public $ignoreSeverityForAudit;

    /**
     * @var array<string, string|null> List of severities to ignore during blocking
     */
    public $ignoreSeverityForBlocking;

    /**
     * @var array<string, string|null> List of abandoned packages to ignore during auditing
     */
    public $ignoreAbandonedForAudit;

    /**
     * @var array<string, string|null> List of abandoned packages to ignore during blocking
     */
    public $ignoreAbandonedForBlocking;

    /**
     * @param Auditor::FORMAT_* $auditFormat
     * @param Auditor::ABANDONED_* $auditAbandoned
     * @param Auditor::FILTERED_* $auditFiltered
     * @param array<string, string|null> $ignoreListForAudit
     * @param array<string, string|null> $ignoreListForBlocking
     * @param array<string, string|null> $ignoreSeverityForAudit
     * @param array<string, string|null> $ignoreSeverityForBlocking
     * @param array<string, string|null> $ignoreAbandonedForAudit
     * @param array<string, string|null> $ignoreAbandonedForBlocking
     */
    public function __construct(bool $audit, string $auditFormat, string $auditAbandoned, string $auditFiltered, bool $blockInsecure, bool $blockAbandoned, IgnoreUnreachable $ignoreUnreachable, array $ignoreListForAudit, array $ignoreListForBlocking, array $ignoreSeverityForAudit, array $ignoreSeverityForBlocking, array $ignoreAbandonedForAudit, array $ignoreAbandonedForBlocking)
    {
        $this->audit = $audit;
        $this->auditFormat = $auditFormat;
        $this->auditAbandoned = $auditAbandoned;
        $this->auditFiltered = $auditFiltered;
        $this->blockInsecure = $blockInsecure;
        $this->blockAbandoned = $blockAbandoned;
        $this->ignoreUnreachable = $ignoreUnreachable;
        $this->ignoreListForAudit = $ignoreListForAudit;
        $this->ignoreListForBlocking = $ignoreListForBlocking;
        $this->ignoreSeverityForAudit = $ignoreSeverityForAudit;
        $this->ignoreSeverityForBlocking = $ignoreSeverityForBlocking;
        $this->ignoreAbandonedForAudit = $ignoreAbandonedForAudit;
        $this->ignoreAbandonedForBlocking = $ignoreAbandonedForBlocking;
    }

    /**
     * Merge a new reason for a key into an existing flat reason map.
     *
     * The bridge to AuditConfig flattens multi-rule package ignores
     * (`'vendor/pkg' => [{reason: a}, {reason: b}]`) into a single map
     * entry. Without merging, the last rule's reason silently overwrites
     * earlier ones; this helper preserves all non-null reasons by joining
     * unique values with "; ".
     *
     * @param array<string, string|null> $map
     */
    private static function mergeReason(array $map, string $key, ?string $newReason): ?string
    {
        if (!array_key_exists($key, $map)) {
            return $newReason;
        }

        $existing = $map[$key];

        if ($newReason === null) {
            return $existing;
        }
        if ($existing === null) {
            return $newReason;
        }
        if ($existing === $newReason) {
            return $existing;
        }

        // Avoid re-appending if newReason is already part of a previously merged value
        $parts = array_map('trim', explode(';', $existing));
        if (in_array($newReason, $parts, true)) {
            return $existing;
        }

        return $existing.'; '.$newReason;
    }

    /**
     * Parse ignore configuration supporting both simple and detailed formats with apply scopes
     *
     * Simple format: ['CVE-123', 'CVE-456'] or ['CVE-123' => 'reason']
     * Detailed format: ['CVE-123' => ['apply' => 'audit|block|all', 'reason' => '...']]
     *
     * @param array<mixed> $config
     * @return array{audit: array<string, string|null>, block: array<string, string|null>}
     */
    private static function parseIgnoreWithApply(array $config): array
    {
        $forAudit = [];
        $forBlock = [];

        foreach ($config as $key => $value) {
            // Simple format: ['CVE-123']
            if (is_int($key) && is_string($value)) {
                $id = $value;
                $apply = 'all';
                $reason = null;
            }
            // Simple format with reason: ['CVE-123' => 'reason']
            elseif (is_string($value)) {
                $id = $key;
                $apply = 'all';
                $reason = $value;
            }
            // Detailed format: ['CVE-123' => ['apply' => '...', 'reason' => '...']]
            elseif (is_array($value)) {
                $id = $key;
                $apply = $value['apply'] ?? 'all';
                $reason = $value['reason'] ?? null;

                // Validate apply value
                if (!in_array($apply, ['audit', 'block', 'all'], true)) {
                    throw new \InvalidArgumentException(
                        "Invalid 'apply' value for '{$id}': {$apply}. Expected 'audit', 'block', or 'all'."
                    );
                }
            }
            // Simple format with null: ['CVE-123' => null]
            elseif ($value === null) {
                $id = $key;
                $apply = 'all';
                $reason = null;
            } else {
                continue;
            }

            // Store in appropriate lists based on apply scope
            if ($apply === 'audit' || $apply === 'all') {
                $forAudit[$id] = $reason;
            }
            if ($apply === 'block' || $apply === 'all') {
                $forBlock[$id] = $reason;
            }
        }

        return [
            'audit' => $forAudit,
            'block' => $forBlock,
        ];
    }

    /**
     * @param Auditor::FORMAT_* $auditFormat
     */
    public static function fromConfig(Config $config, bool $audit = true, string $auditFormat = Auditor::FORMAT_SUMMARY): self
    {
        $auditConfig = $config->get('audit');

        // Parse ignore lists with apply scopes
        $ignoreListParsed = self::parseIgnoreWithApply($auditConfig['ignore'] ?? []);
        $ignoreAbandonedParsed = self::parseIgnoreWithApply($auditConfig['ignore-abandoned'] ?? []);
        $ignoreSeverityParsed = self::parseIgnoreWithApply($auditConfig['ignore-severity'] ?? []);

        return new self(
            $audit,
            $auditFormat,
            $auditConfig['abandoned'] ?? Auditor::ABANDONED_FAIL,
            $auditConfig['filtered'] ?? Auditor::FILTERED_FAIL,
            (bool) ($auditConfig['block-insecure'] ?? true),
            (bool) ($auditConfig['block-abandoned'] ?? false),
            IgnoreUnreachable::fromRawAuditConfig($auditConfig),
            $ignoreListParsed['audit'],
            $ignoreListParsed['block'],
            $ignoreSeverityParsed['audit'],
            $ignoreSeverityParsed['block'],
            $ignoreAbandonedParsed['audit'],
            $ignoreAbandonedParsed['block']
        );
    }

    /**
     * Create an AuditConfig from a PolicyConfig (BC bridge).
     *
     * This allows existing code that consumes AuditConfig to work unchanged
     * while the config source migrates from config.audit.* to config.policy.*.
     *
     * @param Auditor::FORMAT_* $auditFormat
     */
    public static function fromPolicyConfig(PolicyConfig $policyConfig, bool $audit = true, string $auditFormat = Auditor::FORMAT_SUMMARY): self
    {
        $adv = $policyConfig->advisories;
        $aba = $policyConfig->abandoned;

        // Map advisory ignore-id rules to the old flat ignoreList format
        $ignoreListForAudit = [];
        $ignoreListForBlock = [];

        foreach ($adv->ignoreId as $id => $rule) {
            if ($rule->onAudit) {
                $ignoreListForAudit[$id] = $rule->reason;
            }
            if ($rule->onBlock) {
                $ignoreListForBlock[$id] = $rule->reason;
            }
        }

        // Also add package-name based ignores from the universal ignore format.
        // The bridge collapses to a flat <key, reason> map, so when multiple rules
        // apply to the same package we merge their reasons rather than letting the
        // last rule silently overwrite the others.
        foreach ($adv->ignore as $pkgName => $rules) {
            foreach ($rules as $rule) {
                if ($rule->onAudit) {
                    $ignoreListForAudit[$pkgName] = self::mergeReason($ignoreListForAudit, $pkgName, $rule->reason);
                }
                if ($rule->onBlock) {
                    $ignoreListForBlock[$pkgName] = self::mergeReason($ignoreListForBlock, $pkgName, $rule->reason);
                }
            }
        }

        // Map severity ignores
        $ignoreSeverityForAudit = [];
        $ignoreSeverityForBlock = [];
        foreach ($adv->ignoreSeverity as $severity => $rule) {
            if ($rule->onAudit) {
                $ignoreSeverityForAudit[$severity] = $rule->reason;
            }
            if ($rule->onBlock) {
                $ignoreSeverityForBlock[$severity] = $rule->reason;
            }
        }

        // Map abandoned ignores
        $ignoreAbandonedForAudit = [];
        $ignoreAbandonedForBlock = [];
        foreach ($aba->ignore as $pkgName => $rules) {
            foreach ($rules as $rule) {
                if ($rule->onAudit) {
                    $ignoreAbandonedForAudit[$pkgName] = self::mergeReason($ignoreAbandonedForAudit, $pkgName, $rule->reason);
                }
                if ($rule->onBlock) {
                    $ignoreAbandonedForBlock[$pkgName] = self::mergeReason($ignoreAbandonedForBlock, $pkgName, $rule->reason);
                }
            }
        }

        // Map abandoned audit mode to old enum
        $abandonedMode = Auditor::ABANDONED_FAIL;
        if ($aba->audit === ListPolicyConfig::AUDIT_IGNORE) {
            $abandonedMode = Auditor::ABANDONED_IGNORE;
        } elseif ($aba->audit === ListPolicyConfig::AUDIT_REPORT) {
            $abandonedMode = Auditor::ABANDONED_REPORT;
        }

        // Filter list audit collapses to a "worst-of" across every list active for audit.
        // Using only $policyConfig->malware->audit would mask other lists (e.g. company-policy)
        // and skip them inside Auditor::audit when malware is set to "ignore".
        // @todo handle this more accurate once we drop the audit config
        $filteredMode = Auditor::FILTERED_IGNORE;
        foreach ($policyConfig->getActiveFilterLists('audit') as $listConfig) {
            if ($listConfig->audit === ListPolicyConfig::AUDIT_FAIL) {
                $filteredMode = Auditor::FILTERED_FAIL;
                break;
            }

            $filteredMode = Auditor::FILTERED_REPORT;
        }

        return new self(
            $audit,
            $auditFormat,
            $abandonedMode,
            $filteredMode,
            $adv->block,
            $aba->block,
            $policyConfig->ignoreUnreachable,
            $ignoreListForAudit,
            $ignoreListForBlock,
            $ignoreSeverityForAudit,
            $ignoreSeverityForBlock,
            $ignoreAbandonedForAudit,
            $ignoreAbandonedForBlock
        );
    }
}
