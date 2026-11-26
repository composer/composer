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

use Composer\Package\BasePackage;

/**
 * Applies a chain of rejection rules to package versions
 *
 * @internal
 * @final
 */
class PackageFilter
{
    /** @var list<RejectionRuleInterface> */
    private $rules;

    /**
     * @param list<RejectionRuleInterface> $rules in the order they apply, which decides the reason recorded when only the first rejection counts
     */
    public function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    /**
     * @param list<BasePackage> $packages
     */
    public function filter(array $packages, bool $collectAllReasons = false): PackageFilterResult
    {
        return $this->apply(new PackageFilterResult($packages), $collectAllReasons);
    }

    /**
     * @param bool $collectAllReasons whether each rule checks every package, so all reasons are known, or only those no earlier rule or the result rejected
     */
    public function apply(PackageFilterResult $result, bool $collectAllReasons = false): PackageFilterResult
    {
        foreach ($this->rules as $rule) {
            $packages = $collectAllReasons ? $result->getPackages() : self::getAcceptedByIndex($result);
            if (\count($packages) === 0) {
                break;
            }

            $result = $result->withReasons($rule->getRejections($packages));
        }

        return $result;
    }

    /**
     * @return array<int, BasePackage>
     */
    private static function getAcceptedByIndex(PackageFilterResult $result): array
    {
        $packages = [];
        foreach ($result->getPackages() as $index => $package) {
            if (\count($result->getReasons($package)) === 0) {
                $packages[$index] = $package;
            }
        }

        return $packages;
    }
}
