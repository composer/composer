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
 */
class AuditConfig
{
    /**
     * @var array<string>|array<string,string> List of advisory IDs, remote IDs or CVE IDs that reported but not listed as vulnerabilities.
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
     * @var bool Should blocking flags also block a composer install command
     */
    public $blockInstall;

    /**
     * @param array<string>|array<string,string> $ignoreList
     * @param Auditor::ABANDONED_* $abandoned
    */
    public function __construct(array $ignoreList, string $abandoned, bool $blockInsecure, bool $blockAbandoned, bool $blockInstall)
    {
        $this->ignoreList = $ignoreList;
        $this->abandoned = $abandoned;
        $this->blockInsecure = $blockInsecure;
        $this->blockAbandoned = $blockAbandoned;
        $this->blockInstall = $blockInstall;
    }

    public static function fromConfig(Config $config): self
    {
        $auditConfig = $config->get('audit');

        return new self(
            $auditConfig['ignore'] ?? [],
                $auditConfig['abandoned'] ?? Auditor::ABANDONED_FAIL,
                (bool) ($auditConfig['block-insecure'] ?? false),
                (bool) ($auditConfig['block-abandoned'] ?? false),
                (bool) ($auditConfig['block-install'] ?? false)
        );
    }
}
