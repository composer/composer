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
    public $abandoned;

    /**
     * @var bool Should insecure versions be blocked during a composer update/required command
     */
    public $blockInsecure;

    /**
     * @var bool Should abandoned packages be blocked during a composer update/required command
     */
    public $blockAbandoned;

    /**
     * @var bool Should repositories that are unreachable or return a non-200 status code be ignored.
     */
    public $ignoreUnreachable;

    /**
     * @var list<string> List of advisory IDs to ignore during auditing
     */
    public $ignoreListForAudit;

    /**
     * @var list<string> List of advisory IDs to ignore during blocking
     */
    public $ignoreListForBlocking;

    /**
     * @var list<string> List of severities to ignore during auditing
     */
    public $ignoreSeverityForAudit;

    /**
     * @var list<string> List of severities to ignore during blocking
     */
    public $ignoreSeverityForBlocking;

    /**
     * @var list<string> List of abandoned packages to ignore during auditing
     */
    public $ignoreAbandonedForAudit;

    /**
     * @var list<string> List of abandoned packages to ignore during blocking
     */
    public $ignoreAbandonedForBlocking;

    /**
     * @param Auditor::FORMAT_* $auditFormat
     * @param Auditor::ABANDONED_* $abandoned
     * @param list<string> $ignoreListForAudit
     * @param list<string> $ignoreListForBlocking
     * @param list<string> $ignoreSeverityForAudit
     * @param list<string> $ignoreSeverityForBlocking
     * @param list<string> $ignoreAbandonedForAudit
     * @param list<string> $ignoreAbandonedForBlocking
     */
    public function __construct(bool $audit, string $auditFormat, string $abandoned, bool $blockInsecure, bool $blockAbandoned, bool $ignoreUnreachable, array $ignoreListForAudit, array $ignoreListForBlocking, array $ignoreSeverityForAudit, array $ignoreSeverityForBlocking, array $ignoreAbandonedForAudit, array $ignoreAbandonedForBlocking)
    {
        $this->audit = $audit;
        $this->auditFormat = $auditFormat;
        $this->abandoned = $abandoned;
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
     * Parse ignore configuration supporting both simple and detailed formats with apply scopes
     *
     * Simple format: ['CVE-123', 'CVE-456'] or ['CVE-123' => 'reason']
     * Detailed format: ['CVE-123' => ['apply' => 'audit|block|all', 'reason' => '...']]
     *
     * @param array<mixed> $config
     * @return array{audit: list<string>, block: list<string>}
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
            }
            // Simple format with reason: ['CVE-123' => 'reason']
            elseif (is_string($value)) {
                $id = $key;
                $apply = 'all';
            }
            // Detailed format: ['CVE-123' => ['apply' => '...', 'reason' => '...']]
            elseif (is_array($value)) {
                $id = $key;
                $apply = $value['apply'] ?? 'all';

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
            }
            else {
                continue;
            }

            // Store in appropriate lists based on apply scope
            if ($apply === 'audit' || $apply === 'all') {
                $forAudit[] = $id;
            }
            if ($apply === 'block' || $apply === 'all') {
                $forBlock[] = $id;
            }
        }

        return [
            'audit' => array_values(array_unique($forAudit)),
            'block' => array_values(array_unique($forBlock)),
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
            (bool) ($auditConfig['block-insecure'] ?? true),
            (bool) ($auditConfig['block-abandoned'] ?? false),
            (bool) ($auditConfig['ignore-unreachable'] ?? false),
            $ignoreListParsed['audit'],
            $ignoreListParsed['block'],
            $ignoreSeverityParsed['audit'],
            $ignoreSeverityParsed['block'],
            $ignoreAbandonedParsed['audit'],
            $ignoreAbandonedParsed['block']
        );
    }
}
