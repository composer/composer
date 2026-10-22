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

namespace Composer\Filter\PackageFilter;

use Composer\Advisory\Auditor;
use Composer\Policy\ListPolicyConfig;

/**
 * Rejects abandoned packages when the abandoned policy blocks them
 *
 * @internal
 * @final
 */
class AbandonedRule implements RejectionRuleInterface
{
    /** @var Auditor */
    private $auditor;
    /** @var ListPolicyConfig */
    private $config;

    public function __construct(Auditor $auditor, ListPolicyConfig $config)
    {
        $this->auditor = $auditor;
        $this->config = $config;
    }

    public function getRejections(array $packages): array
    {
        if (!$this->config->block) {
            return [];
        }

        $ignore = $this->config->getFlatIgnoreForOperation('block');
        $rejections = [];
        foreach ($packages as $key => $package) {
            if (\count($this->auditor->filterAbandonedPackages([$package], $ignore)) > 0) {
                $rejections[$key] = RejectionReason::abandoned($package->getName(), $package->getPrettyVersion());
            }
        }

        return $rejections;
    }
}
