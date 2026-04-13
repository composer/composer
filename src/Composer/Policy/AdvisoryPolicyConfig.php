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
 * @internal
 * @final
 * @readonly
 */
class AdvisoryPolicyConfig extends ListPolicyConfig
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
             $ignore,
            true
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
}
