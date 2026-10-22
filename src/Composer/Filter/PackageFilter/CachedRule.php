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

use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;

/**
 * Remembers what a rule decided for each package version, so rules that fetch remote data do it once per version
 * even when packages are looked up one by one, and once for all of them after a preload
 *
 * @internal
 * @final
 */
class CachedRule implements RejectionRuleInterface
{
    /** @var RejectionRuleInterface */
    private $rule;
    /** @var array<string, ?RejectionReason> package key => rejection, null if accepted */
    private $decisions = [];

    public function __construct(RejectionRuleInterface $rule)
    {
        $this->rule = $rule;
    }

    public function getRejections(array $packages): array
    {
        $uncached = [];
        foreach ($packages as $key => $package) {
            if (!array_key_exists(self::getCacheKey($package), $this->decisions)) {
                $uncached[$key] = $package;
            }
        }

        if (\count($uncached) > 0) {
            $rejections = $this->rule->getRejections($uncached);
            foreach ($uncached as $key => $package) {
                $this->decisions[self::getCacheKey($package)] = $rejections[$key] ?? null;
            }
        }

        $rejections = [];
        foreach ($packages as $key => $package) {
            $reason = $this->decisions[self::getCacheKey($package)];
            if ($reason !== null) {
                $rejections[$key] = $reason;
            }
        }

        return $rejections;
    }

    /**
     * Repositories may create new package instances on each load, so versions are told apart by repository, name and version
     */
    private static function getCacheKey(BasePackage $package): string
    {
        $repository = $package->getRepository();

        return ($repository !== null ? spl_object_id($repository) : '').' '.($package instanceof AliasPackage ? 'alias ' : '').$package->getUniqueName();
    }
}
