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
use Composer\Semver\VersionParser;
use Composer\Util\Platform;

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

    /** @var AdvisoriesPolicyConfig */
    public $advisories;

    /** @var MalwarePolicyConfig */
    public $malware;

    /** @var ListPolicyConfig */
    public $abandoned;

    /** @var array<string, CustomListPolicyConfig> Custom named lists */
    public $customLists;

    /** @var IgnoreUnreachable For which operations unreachable repositories and policy sources should be silently ignored */
    public $ignoreUnreachable;

    /**
     * Names reserved for built-in lists — repos must not advertise these.
     */
    public const RESERVED_NAMES = [
        AdvisoriesPolicyConfig::NAME,
        AbandonedPolicyConfig::NAME,
    ];

    public const BUILTIN_LIST_NAMES = [
        AdvisoriesPolicyConfig::NAME,
        AbandonedPolicyConfig::NAME,
        MalwarePolicyConfig::NAME,
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
    public const NON_LIST_KEYS = [
        'ignore-unreachable',
    ];

    /**
     * @param array<string, CustomListPolicyConfig> $customLists
     */
    public function __construct(
        bool $enabled,
        AdvisoriesPolicyConfig $advisories,
        MalwarePolicyConfig $malware,
        ListPolicyConfig $abandoned,
        array $customLists,
        IgnoreUnreachable $ignoreUnreachable
    ) {
        $this->enabled = $enabled;
        $this->advisories = $advisories;
        $this->malware = $malware;
        $this->abandoned = $abandoned;
        $this->customLists = $customLists;
        $this->ignoreUnreachable = $ignoreUnreachable;
    }

    /**
     * Returns the reason the given name collides with a future-reserved identifier
     * (FUTURE_RESERVED_PREFIXES or FUTURE_RESERVED_NAMES), or null if it does not.
     */
    public static function getFutureReservedListNameError(string $listName): ?string
    {
        foreach (self::FUTURE_RESERVED_PREFIXES as $prefix) {
            if (str_starts_with($listName, $prefix)) {
                return sprintf('"%s" starts with reserved prefix "%s".', $listName, $prefix);
            }
        }

        if (in_array($listName, self::FUTURE_RESERVED_NAMES, true)) {
            return sprintf('"%s" is reserved for future use.', $listName);
        }

        return null;
    }

    /**
     * Reject custom-list names that collide with reserved or future-reserved
     * identifiers (RESERVED_NAMES, FUTURE_RESERVED_NAMES, or any
     * FUTURE_RESERVED_PREFIXES entry).
     *
     * In the normal fromConfig flow, built-in list keys (`advisories`, `malware`,
     * `abandoned`) and known non-list sibling keys (`ignore-unreachable`) are
     * filtered out before this check. The RESERVED_NAMES check is therefore
     * defence-in-depth for `advisories` and `abandoned` if that loop skip ever
     * changes; `malware` is intentionally absent from RESERVED_NAMES because
     * repositories are allowed to advertise a `malware` list, so it relies
     * solely on the loop's BUILTIN_LIST_NAMES skip.
     */
    private static function assertCustomListNameAllowed(string $listName): void
    {
        if (in_array($listName, self::RESERVED_NAMES, true)) {
            throw new \UnexpectedValueException(sprintf(
                'Invalid custom policy list name "%s": this name is reserved for a built-in list.',
                $listName
            ));
        }

        $error = self::getFutureReservedListNameError($listName);
        if ($error !== null) {
            throw new \UnexpectedValueException('Invalid custom policy list name: '.$error);
        }
    }

    /**
     * Reads config.policy with BC fallback to config.audit.
     */
    public static function fromConfig(Config $config): self
    {
        $policyRaw = $config->get('policy');
        $auditRaw = $config->get('audit');
        $parser = new VersionParser();

        if ($policyRaw === false) {
            return new self(
                false,
                AdvisoriesPolicyConfig::disabled(),
                MalwarePolicyConfig::disabled(),
                AbandonedPolicyConfig::disabled(),
                [],
                IgnoreUnreachable::all()
            );
        }

        $policyConfig = is_array($policyRaw) ? $policyRaw : [];
        $auditConfig = is_array($auditRaw) ? $auditRaw : [];

        $advisories = AdvisoriesPolicyConfig::fromRawConfig($policyConfig, $auditConfig, $parser);
        $malware = MalwarePolicyConfig::fromRawConfig($policyConfig, $parser);
        $abandoned = AbandonedPolicyConfig::fromRawConfig($policyConfig, $auditConfig, $parser);

        $customLists = [];
        foreach ($policyConfig as $listName => $listConfig) {
            if (in_array($listName, self::BUILTIN_LIST_NAMES, true) || in_array($listName, self::NON_LIST_KEYS, true)) {
                continue;
            }

            self::assertCustomListNameAllowed((string) $listName);

            $customLists[$listName] = CustomListPolicyConfig::fromRawConfig($listName, $listConfig, $parser);
        }

        $ignoreUnreachable = IgnoreUnreachable::default();
        if (isset($policyConfig['ignore-unreachable'])) {
            $ignoreUnreachable = IgnoreUnreachable::fromRawPolicyConfig($policyConfig);
        } elseif (isset($auditConfig['ignore-unreachable'])) {
            $ignoreUnreachable = IgnoreUnreachable::fromRawAuditConfig($auditConfig);
        }

        $advisoriesBlockOverride = Platform::getBoolEnv('COMPOSER_POLICY_ADVISORIES_BLOCK');
        if (null !== $advisoriesBlockOverride) {
            $advisories = new AdvisoriesPolicyConfig(
                $advisoriesBlockOverride,
                $advisories->audit,
                $advisories->ignore,
                $advisories->ignoreId,
                $advisories->ignoreSeverity
            );
        }

        $malwareBlockOverride = Platform::getBoolEnv('COMPOSER_POLICY_MALWARE_BLOCK');
        if (null !== $malwareBlockOverride) {
            $malware = new MalwarePolicyConfig(
                $malwareBlockOverride,
                $malware->audit,
                $malware->blockScope,
                $malware->ignore,
                $malware->ignoreSource
            );
        }

        // COMPOSER_POLICY_ABANDONED_BLOCK is the canonical name following the
        // COMPOSER_POLICY_<LIST>_BLOCK pattern; COMPOSER_SECURITY_BLOCKING_ABANDONED
        // is the legacy alias and only applies when the canonical var is unset.
        $abandonedBlockOverride = Platform::getBoolEnv('COMPOSER_POLICY_ABANDONED_BLOCK') ?? Platform::getBoolEnv('COMPOSER_SECURITY_BLOCKING_ABANDONED');
        if ($abandonedBlockOverride !== null) {
            $abandoned = new AbandonedPolicyConfig(
                $abandonedBlockOverride,
                $abandoned->audit,
                $abandoned->ignore
            );
        }

        $auditAbandonedEnv = Platform::getEnv('COMPOSER_AUDIT_ABANDONED');
        if (false !== $auditAbandonedEnv) {
            $allowed = [ListPolicyConfig::AUDIT_IGNORE, ListPolicyConfig::AUDIT_REPORT, ListPolicyConfig::AUDIT_FAIL];
            if (!in_array($auditAbandonedEnv, $allowed, true)) {
                throw new \RuntimeException(
                    "Invalid value for COMPOSER_AUDIT_ABANDONED: {$auditAbandonedEnv}. Expected one of ".implode(', ', $allowed)."."
                );
            }
            $abandoned = new AbandonedPolicyConfig(
                $abandoned->block,
                $auditAbandonedEnv,
                $abandoned->ignore
            );
        }

        return new self(
            true,
            $advisories,
            $malware,
            $abandoned,
            $customLists,
            $ignoreUnreachable
        );
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
     * Filter lists active for `composer audit` reporting.
     *
     * @return array<string, ListPolicyConfig>
     */
    public function getActiveAuditFilterLists(): array
    {
        $lists = [];
        foreach ($this->filterableLists() as $name => $list) {
            if ($list->audit !== ListPolicyConfig::AUDIT_IGNORE) {
                $lists[$name] = $list;
            }
        }

        return $lists;
    }

    /**
     * Filter lists active for blocking during the given block scope (update/install).
     *
     * @param ListPolicyConfig::BLOCK_SCOPE_* $blockScope
     * @return array<string, ListPolicyConfig>
     */
    public function getActiveBlockFilterLists(string $blockScope): array
    {
        $lists = [];
        foreach ($this->filterableLists() as $name => $list) {
            if ($list->shouldBlock($blockScope)) {
                $lists[$name] = $list;
            }
        }

        return $lists;
    }

    /**
     * @return list<string>
     */
    public function getActiveAuditFilterListNames(): array
    {
        return array_keys($this->getActiveAuditFilterLists());
    }

    /**
     * @param ListPolicyConfig::BLOCK_SCOPE_* $blockScope
     * @return list<string>
     */
    public function getActiveBlockFilterListNames(string $blockScope): array
    {
        return array_keys($this->getActiveBlockFilterLists($blockScope));
    }

    /**
     * Lists eligible for filter-list filtering (i.e. all lists except `advisories`
     * and `abandoned`, which are surfaced via dedicated code paths).
     *
     * @return array<string, ListPolicyConfig>
     */
    private function filterableLists(): array
    {
        $allLists = $this->getAllLists();
        unset($allLists['abandoned'], $allLists['advisories']);

        return $allLists;
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
     * Create a copy with all blocking disabled (for --no-blocking / --no-security-blocking / COMPOSER_NO_SECURITY_BLOCKING / COMPOSER_NO_BLOCKING).
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
            $this->ignoreUnreachable
        );
    }

    /**
     * Flip the listed scopes (audit, install, update) to true, leaving the
     * remaining scopes at their current value. At least one scope is required —
     * the caller must declare the scope they intend to widen.
     */
    public function withIgnoreUnreachable(string ...$scopes): self
    {
        return new self(
            $this->enabled,
            $this->advisories,
            $this->malware,
            $this->abandoned,
            $this->customLists,
            $this->ignoreUnreachable->with(...$scopes)
        );
    }

    /**
     * @param list<string> $severities
     */
    public function withIgnoreSeverity(array $severities): self
    {
        return new self(
            $this->enabled,
            $this->advisories->withIgnoreSeverity($severities),
            $this->malware,
            $this->abandoned,
            $this->customLists,
            $this->ignoreUnreachable
        );
    }

    /**
     * `$abandoned` overrides only the abandoned list. `$filtered` is a blunt
     * override: it overwrites the audit setting for malware *and every custom
     * list*, including lists explicitly configured as audit=fail in the policy
     * config.
     *
     * @param null|ListPolicyConfig::AUDIT_* $abandoned
     * @param null|ListPolicyConfig::AUDIT_* $filtered
     * @return static
     */
    public function withAudit(?string $abandoned, ?string $filtered)
    {
        $customLists = [];
        foreach ($this->customLists as $name => $list) {
            $customLists[$name] = $list->withAudit($filtered !== null ? $filtered : $list->audit);
        }

        return new self(
            $this->enabled,
            $this->advisories,
            $this->malware->withAudit($filtered !== null ? $filtered : $this->malware->audit),
            $this->abandoned->withAudit($abandoned !== null ? $abandoned : $this->abandoned->audit),
            $customLists,
            $this->ignoreUnreachable
        );
    }
}
