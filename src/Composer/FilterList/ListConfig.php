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

namespace Composer\FilterList;

/**
 * @readonly
 * @internal
 * @final
 */
class ListConfig
{
    /**
     * @var list<string>
     */
    public $categories;

    /**
     * @var list<string>
     */
    public $excludeCategories;

    /**
     * A map of package name to dont filter config
     * @var array<string, DontFilterPackage>
     */
    public $dontFilterPackages;

    /**
     * @param array<string, DontFilterPackage> $dontFilterPackages
     * @param list<string> $categories
     * @param list<string> $excludeCategories
     */
    public function __construct(
        array $categories = [],
        array $excludeCategories = [],
        array $dontFilterPackages = []
    ) {
        $this->categories = $categories;
        $this->excludeCategories = $excludeCategories;
        $this->dontFilterPackages = $dontFilterPackages;
    }

    /**
     * @param array<mixed> $config
     * @param 'all'|'block'|'audit' $operation
     */
    public function apply(array $config, string $operation): ?ListConfig
    {
        if (isset($config['apply']) && !\in_array($config['apply'], ['all', $operation], true)) {
            return null;
        }

        $allIgnorePackages = array_map(function ($config) {
            return DontFilterPackage::fromConfig($config);
        }, $config['dont-filter-packages'] ?? $this->dontFilterPackages);
        $dontFilterPackages = array_values(array_filter($allIgnorePackages, function (DontFilterPackage $entry) use ($operation): bool{
            return \in_array($entry->apply, ['all', $operation], true);
        }));

        $dontFilterPackagesMap = [];
        foreach ($dontFilterPackages as $entry) {
            $dontFilterPackagesMap[$entry->packageName] = $entry;
        }

        return new self(
            $config['categories'] ?? $this->categories,
            $config['exclude-categories'] ?? $this->excludeCategories,
                $dontFilterPackagesMap
        );
    }
}
