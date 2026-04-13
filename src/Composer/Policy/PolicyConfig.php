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

use Composer\Advisory\Auditor;
use Composer\Config;
use Composer\FilterList\Source\UrlSource;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Semver\VersionParser;

/**
 * Unified policy configuration.
 *
 * Parses config.policy (with BC support for config.audit and config.filter)
 * into a collection of ListPolicyConfig objects.
 *
 * @internal
 * @final
 * @readonly
 */
class PolicyConfig
{
    /** @var bool Main switch — false disables all policy enforcement */
    public $enabled;

    /** @var AdvisoryPolicyConfig */
    public $advisories;

    /** @var MalwarePolicyConfig */
    public $malware;

    /** @var ListPolicyConfig */
    public $abandoned;

    /** @var array<string, CustomListPolicyConfig> Custom named lists */
    public $customLists;

    /** @var IgnoreUnreachable For which operations unreachable repositories and policy sources should be silently ignored */
    public $ignoreUnreachable;

    /** @var bool Whether audit should be run @todo remove once the BC layer is gone  */
    public $audit;

    /** @var Auditor::FORMAT_* @todo remove once the BC layer is gone */
    public $auditFormat;

    /**
     * Names reserved for built-in lists — repos must not advertise these.
     */
    public const RESERVED_NAMES = [
        AdvisoryPolicyConfig::NAME,
        AbandonedPolicyConfig::NAME,
    ];

    /**
     * Names reserved for future use.
     */
    public const FUTURE_RESERVED_PREFIXES = [
        'ignore',
    ];

    public const FUTURE_RESERVED_NAMES = [
        'package', 'packages',
        'license', 'licence', 'licenses', 'licences',
        'support', 'maintenance', 'security',
        'minimum-release-age',
    ];

    /**
     * Keys that are not list names at the top level of the policy config.
     */
    private const NON_LIST_KEYS = [
        'ignore-unreachable',
    ];

    /**
     * @param array<string, CustomListPolicyConfig> $customLists
     * @param Auditor::FORMAT_* $auditFormat
     */
    public function __construct(
        bool $enabled,
        AdvisoryPolicyConfig $advisories,
        MalwarePolicyConfig $malware,
        ListPolicyConfig $abandoned,
        array $customLists,
        IgnoreUnreachable $ignoreUnreachable,
        bool $audit = true,
        string $auditFormat = Auditor::FORMAT_SUMMARY
    ) {
        $this->enabled = $enabled;
        $this->advisories = $advisories;
        $this->malware = $malware;
        $this->abandoned = $abandoned;
        $this->customLists = $customLists;
        $this->ignoreUnreachable = $ignoreUnreachable;
        $this->audit = $audit;
        $this->auditFormat = $auditFormat;
    }

    /**
     * Build a PolicyConfig from Composer's Config object.
     *
     * Reads config.policy with BC fallback to config.audit and config.filter.
     *
     * @param Auditor::FORMAT_* $auditFormat
     */
    public static function fromConfig(Config $config, bool $audit = true, string $auditFormat = Auditor::FORMAT_SUMMARY): self
    {
        $policyRaw = $config->get('policy');
        $auditRaw = $config->get('audit');
        $parser = new VersionParser();

        // Master switch
        $enabled = true;
        if ($policyRaw === false) {
            $enabled = false;
        }
        // BC: COMPOSER_POLICY env var handled in Config::get()

        $policyConfig = is_array($policyRaw) ? $policyRaw : [];
        $auditConfig = is_array($auditRaw) ? $auditRaw : [];

        // ========================
        // Advisories
        // ========================
        $advConfig = $policyConfig['advisories'] ?? [];
        if ($advConfig === false) {
            $advConfig = ['block' => false, 'audit' => ListPolicyConfig::AUDIT_IGNORE];
        }
        if (!is_array($advConfig)) {
            $advConfig = [];
        }

        // BC: audit.block-insecure → advisories.block
        $advBlock = $advConfig['block'] ?? $auditConfig['block-insecure'] ?? true;
        // BC: no direct audit.* equivalent for advisories audit mode — advisories always "fail" in old config
        $advAudit = $advConfig['audit'] ?? ListPolicyConfig::AUDIT_FAIL;

        // BC: audit.ignore contained both package names and advisory IDs mixed together
        // New config splits them: policy.advisories.ignore (packages) vs policy.advisories.ignore-id (IDs)
        $advIgnore = IgnorePackageRule::parseIgnoreMap($advConfig['ignore'] ?? [], $parser);
        $advIgnoreId = IgnoreIdRule::parseIgnoreIdMap($advConfig['ignore-id'] ?? []);
        $advIgnoreSeverity = IgnoreSeverityRule::parseIgnoreSeverityMap($advConfig['ignore-severity'] ?? []);

        // BC: merge from audit.ignore (old format had IDs and package names mixed)
        if (isset($auditConfig['ignore']) && !isset($policyConfig['advisories'])) {
            $legacyIgnore = self::parseLegacyAuditIgnore($auditConfig['ignore'], $parser);
            $advIgnore = array_merge_recursive($legacyIgnore['packages'], $advIgnore);
            $advIgnoreId = array_merge($legacyIgnore['ids'], $advIgnoreId);
        }

        // BC: merge from audit.ignore-severity
        if (isset($auditConfig['ignore-severity']) && !isset($policyConfig['advisories'])) {
            $legacySev = self::parseLegacyIgnoreWithApply($auditConfig['ignore-severity']);
            foreach ($legacySev as $sev => $item) {
                if (!isset($advIgnoreSeverity[$sev])) {
                    $advIgnoreSeverity[$sev] = new IgnoreSeverityRule($sev, $item['reason'], $item['onBlock'], $item['onAudit']);
                }
            }
        }

        $advisories = new AdvisoryPolicyConfig(
            (bool) $advBlock,
            $advAudit,
            $advIgnore,
            $advIgnoreId,
            $advIgnoreSeverity
        );

        // ========================
        // Malware
        // ========================
        $malConfig = $policyConfig['malware'] ?? [];
        if ($malConfig === false) {
            $malConfig = ['block' => false, 'audit' => ListPolicyConfig::AUDIT_IGNORE];
        }
        if (!is_array($malConfig)) {
            $malConfig = [];
        }

        $malBlock = $malConfig['block'] ?? true;
        $malAudit = $malConfig['audit'] ?? ListPolicyConfig::AUDIT_FAIL;
        $malBlockScope = $malConfig['block-scope'] ?? MalwarePolicyConfig::BLOCK_SCOPE_ALL;
        $malIgnore = IgnorePackageRule::parseIgnoreMap($malConfig['ignore'] ?? [], $parser);
        $malIgnoreSource = $malConfig['ignore-source'] ?? [];

        $malware = new MalwarePolicyConfig(
            (bool) $malBlock,
            $malAudit,
            $malBlockScope,
            $malIgnore,
            $malIgnoreSource
        );

        // ========================
        // Abandoned
        // ========================
        $abaConfig = $policyConfig['abandoned'] ?? [];
        if ($abaConfig === false) {
            $abaConfig = ['block' => false, 'audit' => ListPolicyConfig::AUDIT_IGNORE];
        }
        if (!is_array($abaConfig)) {
            $abaConfig = [];
        }

        // BC: audit.block-abandoned → abandoned.block
        $abaBlock = $abaConfig['block'] ?? $auditConfig['block-abandoned'] ?? false;
        // BC: audit.abandoned → abandoned.audit
        $abaAudit = $abaConfig['audit'] ?? $auditConfig['abandoned'] ?? ListPolicyConfig::AUDIT_FAIL;
        $abaIgnore = IgnorePackageRule::parseIgnoreMap($abaConfig['ignore'] ?? [], $parser);

        // BC: merge from audit.ignore-abandoned
        if (isset($auditConfig['ignore-abandoned']) && !isset($policyConfig['abandoned'])) {
            $legacyAba = self::parseLegacyIgnoreWithApply($auditConfig['ignore-abandoned']);
            foreach ($legacyAba as $pkg => $item) {
                if (!isset($abaIgnore[$pkg])) {
                    $abaIgnore[$pkg] = [new IgnorePackageRule(
                        $pkg,
                        new MatchAllConstraint(),
                        $item['reason'],
                        $item['onBlock'],
                        $item['onAudit']
                    )];
                }
            }
        }

        $abandoned = new AbandonedPolicyConfig(
            (bool) $abaBlock,
            $abaAudit,
            $abaIgnore
        );

        // ========================
        // Custom lists
        // ========================
        $customLists = [];
        $builtInKeys = ['advisories', 'malware', 'abandoned'];

        foreach ($policyConfig as $listName => $listConfig) {
            if (in_array($listName, $builtInKeys, true) || in_array($listName, self::NON_LIST_KEYS, true)) {
                continue;
            }

            if ($listConfig === false) {
                continue;
            }

            if ($listConfig === true) {
                $listConfig = [];
            }

            if (!is_array($listConfig)) {
                continue;
            }

            $sources = [];
            foreach ($listConfig['sources'] ?? [] as $sourceConfig) {
                if (is_array($sourceConfig) && isset($sourceConfig['type']) && $sourceConfig['type'] === 'url') {
                    if (!isset($sourceConfig['url']) || strpos($sourceConfig['url'], 'https://') === false) {
                        throw new \RuntimeException('Invalid source config for list "'.$listName.'". "url" is required and must start with "https://".');
                    }
                    $sources[] = new UrlSource($listName, $sourceConfig['url']);
                }
            }

            $customLists[$listName] = new CustomListPolicyConfig(
                $listName,
                (bool) ($listConfig['block'] ?? true),
                $listConfig['audit'] ?? ListPolicyConfig::AUDIT_FAIL,
                IgnorePackageRule::parseIgnoreMap($listConfig['ignore'] ?? [], $parser),
                $sources
            );
        }

        // ========================
        // Global settings
        // ========================
        $ignoreUnreachable = IgnoreUnreachable::default();
        if (isset($policyConfig['ignore-unreachable'])) {
            $ignoreUnreachable = IgnoreUnreachable::fromRawPolicyConfig($policyConfig);
        } elseif (isset($auditConfig['ignore-unreachable'])) {
            $ignoreUnreachable = IgnoreUnreachable::fromRawAuditConfig($auditConfig);
        }

        // BC: env var overrides (these are handled here because Config::get('policy')
        // only returns the raw config array; specific overrides need to be applied after parsing)
        $blockAbandonedEnv = \Composer\Util\Platform::getEnv('COMPOSER_SECURITY_BLOCKING_ABANDONED');
        if (false !== $blockAbandonedEnv) {
            $abandoned = new AbandonedPolicyConfig(
                (bool) (int) $blockAbandonedEnv,
                $abandoned->audit,
                $abandoned->ignore
            );
        }

        $auditAbandonedEnv = \Composer\Util\Platform::getEnv('COMPOSER_AUDIT_ABANDONED');
        if (false !== $auditAbandonedEnv && in_array($auditAbandonedEnv, [ListPolicyConfig::AUDIT_IGNORE, ListPolicyConfig::AUDIT_REPORT, ListPolicyConfig::AUDIT_FAIL], true)) {
            $abandoned = new AbandonedPolicyConfig(
                $abandoned->block,
                $auditAbandonedEnv,
                $abandoned->ignore
            );
        }

        return new self($enabled, $advisories, $malware, $abandoned, $customLists, $ignoreUnreachable, $audit, $auditFormat);
    }

    /**
     * Get all list configs (built-in + custom).
     *
     * @return array<string, ListPolicyConfig>
     */
    public function getAllLists(): array
    {
        return array_merge([
            'advisories' => $this->advisories,
            'malware' => $this->malware,
            'abandoned' => $this->abandoned,
        ], $this->customLists);
    }

    /**
     * @param 'audit'|'block' $operation
     * @return array<string, ListPolicyConfig>
     */
    public function getActiveFilterLists(string $operation): array
    {
        $allLists = $this->getAllLists();
        unset($allLists['abandoned'], $allLists['advisories']);

        $lists = [];
        foreach ($allLists as $name => $list) {
            if ($operation === 'audit' && $list->audit !== ListPolicyConfig::AUDIT_IGNORE) {
                $lists[$name] = $list;
            }

            if ($operation === 'block' && $list->block) {
                $lists[$name] = $list;
            }
        }

        return $lists;
    }

    /**
     * @param 'audit'|'block' $operation
     * @return list<string>
     */
    public function getActiveFilterListNames(string $operation): array
    {
        return array_keys($this->getActiveFilterLists($operation));
    }

    /**
     * Get all custom list configs that have URL sources.
     *
     * @return array<string, CustomListPolicyConfig>
     */
    public function getCustomListsWithSources(): array
    {
        return array_filter($this->customLists, static function (CustomListPolicyConfig $list): bool {
            return count($list->sources) > 0;
        });
    }

    /**
     * Create a copy with all blocking disabled (for --no-blocking / COMPOSER_NO_SECURITY_BLOCKING).
     */
    public function withBlockingDisabled(): self
    {
        $customLists = [];
        foreach ($this->customLists as $name => $list) {
            $customLists[$name] = $list->withBlockingDisabled();
        }

        return new self(
            $this->enabled,
            $this->advisories->withBlockingDisabled(),
            $this->malware->withBlockingDisabled(),
            $this->abandoned->withBlockingDisabled(),
            $customLists,
            $this->ignoreUnreachable,
            $this->audit,
            $this->auditFormat
        );
    }

    /**
     * Parse the legacy audit.ignore format which mixed advisory IDs and package names.
     *
     * Advisory IDs look like CVE-*, GHSA-*, or contain no '/'.
     * Package names contain '/'.
     *
     * @param array<mixed> $config
     * @return array{packages: array<string, list<IgnorePackageRule>>, ids: array<string, IgnoreIdRule>}
     */
    private static function parseLegacyAuditIgnore(array $config, VersionParser $parser): array
    {
        $packages = [];
        $ids = [];

        foreach ($config as $key => $value) {
            // Determine the identifier
            $id = is_int($key) ? (string) $value : $key;

            // Detect if this is a package name (contains /) or an advisory ID
            $isPackageName = strpos($id, '/') !== false;

            // Parse the apply/reason from old format
            $parsed = self::parseLegacySingleIgnore($key, $value);

            if ($isPackageName) {
                $packages[$id][] = new IgnorePackageRule(
                    $id,
                    new MatchAllConstraint(),
                    $parsed['reason'],
                    $parsed['onBlock'],
                    $parsed['onAudit']
                );
            } else {
                $ids[$id] = new IgnoreIdRule($id, $parsed['reason'], $parsed['onBlock'], $parsed['onAudit']);
            }
        }

        return ['packages' => $packages, 'ids' => $ids];
    }

    /**
     * Parse the legacy ignore format that used "apply": "audit|block|all".
     *
     * @param array<mixed> $config
     * @return array<string, array{reason: string|null, onBlock: bool, onAudit: bool}>
     */
    private static function parseLegacyIgnoreWithApply(array $config): array
    {
        $result = [];

        foreach ($config as $key => $value) {
            $id = is_int($key) ? (string) $value : $key;
            $result[$id] = self::parseLegacySingleIgnore($key, $value);
        }

        return $result;
    }

    /**
     * Parse a single entry from the legacy ignore format.
     *
     * @param int|string $key
     * @param mixed $value
     * @return array{reason: string|null, onBlock: bool, onAudit: bool}
     */
    private static function parseLegacySingleIgnore($key, $value): array
    {
        $reason = null;
        $onBlock = true;
        $onAudit = true;

        if (is_int($key) && is_string($value)) {
            // Simple: ['CVE-123']
        } elseif (is_string($value)) {
            // With reason: ['CVE-123' => 'reason']
            $reason = $value;
        } elseif (is_array($value)) {
            // Detailed: ['CVE-123' => ['apply' => '...', 'reason' => '...']]
            $apply = $value['apply'] ?? 'all';
            $reason = $value['reason'] ?? null;
            $onBlock = in_array($apply, ['block', 'all'], true);
            $onAudit = in_array($apply, ['audit', 'all'], true);
        }

        return ['reason' => $reason, 'onBlock' => $onBlock, 'onAudit' => $onAudit];
    }

}
