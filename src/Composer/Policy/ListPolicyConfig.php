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

use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Semver\VersionParser;

/**
 * Configuration for a single policy list (advisories, malware, abandoned, or custom).
 *
 * Every list follows the same skeleton:
 * - block: bool
 * - audit: self::AUDIT_*
 * - ignore: IgnorePackageRule[]
 *
 * @readonly
 * @internal
 */
abstract class ListPolicyConfig
{
    public const AUDIT_IGNORE = 'ignore';
    public const AUDIT_REPORT = 'report';
    public const AUDIT_FAIL = 'fail';

    /**
     * "update" = block during update/require only
     * "install" = block during install only
     * "all" = block during both
     */
    public const BLOCK_SCOPE_UPDATE = 'update';
    public const BLOCK_SCOPE_INSTALL = 'install';
    public const BLOCK_SCOPE_ALL = 'all';

    /** @var string List name */
    public $name;

    /** @var bool Whether this list blocks matching versions during update/require */
    public $block;

    /** @var self::AUDIT_* How composer audit treats matches from this list */
    public $audit;

    /**
     * Package-level ignore rules
     * @var array<string, list<IgnorePackageRule>>
     */
    public $ignore;

    /**
     * @param array<string, list<IgnorePackageRule>> $ignore
     * @param self::AUDIT_* $audit
     */
    public function __construct(
        string $name,
        bool $block,
        string $audit,
        array $ignore
    ) {
        $this->name = $name;
        $this->block = $block;
        $this->audit = $audit;
        $this->ignore = $ignore;
    }

    /**
     * Whether blocking applies to a given command context.
     *
     * @param self::BLOCK_SCOPE_* $blockScope
     */
    public function shouldBlock(string $blockScope): bool
    {
        return $this->block;
    }

    /**
     * Get ignore rules filtered for a specific operation.
     *
     * @param 'block'|'audit' $operation
     * @return array<string, list<IgnorePackageRule>>
     */
    public function getIgnoreForOperation(string $operation): array
    {
        return IgnorePackageRule::filterByOperation($this->ignore, $operation);
    }

    /**
     * @return static
     */
    abstract public function withBlockingDisabled();

    /**
     * Parse the legacy audit.ignore format which mixed advisory IDs and package names.
     *
     * Advisory IDs look like CVE-*, GHSA-*, or contain no '/'.
     * Package names contain '/'.
     *
     * @param array<mixed> $config
     * @return array{packages: array<string, list<IgnorePackageRule>>, ids: array<string, IgnoreIdRule>}
     */
    protected static function parseLegacyAuditIgnore(array $config, VersionParser $parser): array
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
     * @return array<string, non-empty-list<IgnorePackageRule>>
     */
    protected static function parseLegacyIgnoreWithApply(array $config): array
    {
        $result = [];
        foreach ($config as $key => $value) {
            $packageName = is_int($key) ? (string) $value : $key;
            $parsed = self::parseLegacySingleIgnore($key, $value);
            $result[$packageName] = [new IgnorePackageRule($packageName, new MatchAllConstraint(), $parsed['reason'], $parsed['onBlock'], $parsed['onAudit'])];
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
    protected static function parseLegacySingleIgnore($key, $value): array
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
