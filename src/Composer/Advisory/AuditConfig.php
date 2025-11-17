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
     * @var array<string>|array<string,string> List of advisory IDs, remote IDs, CVE IDs or package names that reported but not listed as vulnerabilities.
     */
    public $ignoreList;

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
     * @var array<string> A list of severities for which advisories with matching severity will be ignored.
     */
    public $ignoreSeverity;

    /**
     * @var bool Should repositories that are unreachable or return a non-200 status code be ignored.
     */
    public $ignoreUnreachable;

    /**
     * @var array<string>|array<string,string> List of abandoned package names that are reported but let the audit pass.
     */
    public $ignoreAbandonedPackages;

    /**
     * @param Auditor::FORMAT_* $auditFormat
     * @param array<string>|array<string,string> $ignoreList List of advisory IDs, remote IDs, CVE IDs or package names to ignore
     * @param Auditor::ABANDONED_* $abandoned
     * @param array<string> $ignoreSeverity
     * @param array<string>|array<string,string> $ignoreAbandonedPackages
     */
    public function __construct(bool $audit, string $auditFormat, array $ignoreList, string $abandoned, bool $blockInsecure, bool $blockAbandoned, array $ignoreSeverity, bool $ignoreUnreachable, array $ignoreAbandonedPackages)
    {
        $this->audit = $audit;
        $this->auditFormat = $auditFormat;
        $this->ignoreList = $ignoreList;
        $this->abandoned = $abandoned;
        $this->blockInsecure = $blockInsecure;
        $this->blockAbandoned = $blockAbandoned;
        $this->ignoreSeverity = $ignoreSeverity;
        $this->ignoreUnreachable = $ignoreUnreachable;
        $this->ignoreAbandonedPackages = $ignoreAbandonedPackages;
    }

    /**
     * @param Auditor::FORMAT_* $auditFormat
     */
    public static function fromConfig(Config $config, bool $audit = true, string $auditFormat = Auditor::FORMAT_SUMMARY): self
    {
        $auditConfig = $config->get('audit');

        return new self(
            $audit,
            $auditFormat,
            $auditConfig['ignore'] ?? [],
            $auditConfig['abandoned'] ?? Auditor::ABANDONED_FAIL,
            (bool) ($auditConfig['block-insecure'] ?? true),
            (bool) ($auditConfig['block-abandoned'] ?? false),
            $auditConfig['ignore-severity'] ?? [],
            (bool) ($auditConfig['ignore-unreachable'] ?? false),
            $auditConfig['ignore-abandoned'] ?? []
        );
    }
}
