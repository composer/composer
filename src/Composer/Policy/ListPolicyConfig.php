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
 * Configuration for a single policy list (advisories, malware, abandoned, or custom).
 *
 * Every list follows the same skeleton:
 * - block: bool
 * - audit: "ignore"|"report"|"fail"
 * - ignore: IgnoreRule[]
 *
 * @readonly
 * @internal
 */
abstract class ListPolicyConfig
{
    public const AUDIT_IGNORE = 'ignore';
    public const AUDIT_REPORT = 'report';
    public const AUDIT_FAIL = 'fail';

    /** @var string List name */
    public $name;

    /** @var bool Whether this list blocks matching versions during update/require */
    public $block;

    /** @var self::AUDIT_* How composer audit treats matches from this list */
    public $audit;

    /**
     * Package-level ignore rules (universal format).
     * @var array<string, list<IgnorePackageRule>>
     */
    public $ignore;

    /** @var bool Whether this list is a built-in (advisories, malware, abandoned) */
    public $builtIn;

    /**
     * @param array<string, list<IgnorePackageRule>> $ignore
     * @param self::AUDIT_* $audit
     */
    public function __construct(
        string $name,
        bool $block,
        string $audit,
        array $ignore,
        bool $builtIn
    ) {
        $this->name = $name;
        $this->block = $block;
        $this->audit = $audit;
        $this->ignore = $ignore;
        $this->builtIn = $builtIn;
    }

    /**
     * Whether blocking applies to a given command context.
     *
     * @param 'update'|'install' $context
     */
    public function shouldBlock(string $context): bool
    {
        return $this->block;
    }

    /**
     * Whether audit should report matches from this list.
     */
    public function shouldAuditReport(): bool
    {
        return $this->audit !== self::AUDIT_IGNORE;
    }

    /**
     * Whether audit should fail on matches from this list.
     */
    public function shouldAuditFail(): bool
    {
        return $this->audit === self::AUDIT_FAIL;
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
}
