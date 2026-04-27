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
        $builtInKeys = ['advisories', 'malware', 'abandoned'];
        foreach ($policyConfig as $listName => $listConfig) {
            if (in_array($listName, $builtInKeys, true) || in_array($listName, self::NON_LIST_KEYS, true)) {
                continue;
            }

            $customLists[$listName] = CustomListPolicyConfig::fromRawConfig($listName, $listConfig, $parser);
        }

        $ignoreUnreachable = IgnoreUnreachable::default();
        if (isset($policyConfig['ignore-unreachable'])) {
            $ignoreUnreachable = IgnoreUnreachable::fromRawPolicyConfig($policyConfig);
        } elseif (isset($auditConfig['ignore-unreachable'])) {
            $ignoreUnreachable = IgnoreUnreachable::fromRawAuditConfig($auditConfig);
        }

        // BC: env var overrides (these are handled here because Config::get('policy')
        // only returns the raw config array; specific overrides need to be applied after parsing)
        $advisoriesBlockEnv = Platform::getEnv('COMPOSER_POLICY_ADVISORIES_BLOCK');
        if (false !== $advisoriesBlockEnv) {
            $advisories = new AdvisoriesPolicyConfig(
                (bool) (int) $advisoriesBlockEnv,
                $advisories->audit,
                $advisories->ignore,
                $advisories->ignoreId,
                $advisories->ignoreSeverity
            );
        }

        $blockAbandonedEnv = Platform::getEnv('COMPOSER_SECURITY_BLOCKING_ABANDONED');
        if (false !== $blockAbandonedEnv) {
            $abandoned = new AbandonedPolicyConfig(
                (bool) (int) $blockAbandonedEnv,
                $abandoned->audit,
                $abandoned->ignore
            );
        }

        $auditAbandonedEnv = Platform::getEnv('COMPOSER_AUDIT_ABANDONED');
        if (false !== $auditAbandonedEnv && in_array($auditAbandonedEnv, [ListPolicyConfig::AUDIT_IGNORE, ListPolicyConfig::AUDIT_REPORT, ListPolicyConfig::AUDIT_FAIL], true)) {
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
            $this->ignoreUnreachable
        );
    }
}
