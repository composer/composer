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

use Composer\Package\Version\VersionParser;

/**
 * @readonly
 * @internal
 * @final
 */
class ListConfig
{
    /**
     * A map of package name to dont filter config
     * @var array<string, DontFilterPackage>
     */
    public $dontFilterPackages;

    /** @var VersionParser */
    private $versionParser;

    /**
     * @param array<string, DontFilterPackage> $dontFilterPackages
     */
    public function __construct(
        VersionParser $versionParser,
        array $dontFilterPackages = []
    ) {
        $this->versionParser = $versionParser;
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
            return DontFilterPackage::fromConfig($config, $this->versionParser);
        }, $config['dont-filter-packages'] ?? $this->dontFilterPackages);
        $dontFilterPackages = array_filter($allIgnorePackages, function (DontFilterPackage $entry) use ($operation): bool{
            return \in_array($entry->apply, ['all', $operation], true);
        });

        $dontFilterPackagesMap = [];
        foreach ($dontFilterPackages as $entry) {
            $dontFilterPackagesMap[$entry->packageName] = $entry;
        }

        return new self(
            $this->versionParser,
                $dontFilterPackagesMap
        );
    }
}
