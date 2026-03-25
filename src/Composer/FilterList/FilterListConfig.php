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

use Composer\Config;
use Composer\FilterList\Source\UrlSource;
use Composer\Package\Version\VersionParser;

/**
 * @readonly
 * @internal
 * @final
 */
class FilterListConfig
{
    /**
     * @var list<DontFilterPackage>
     */
    public $dontFilterPackages;

    /**
     * @var list<UrlSource>
     */
    public $sources;

    /**
     * @var bool
     */
    public $ignoreUnreachable;

    /**
     * @param list<DontFilterPackage> $dontFilterPackages
     * @param list<UrlSource> $sources
     */
    public function __construct(array $dontFilterPackages, array $sources, bool $ignoreUnreachable)
    {
        $this->dontFilterPackages = $dontFilterPackages;
        $this->sources = $sources;
        $this->ignoreUnreachable = $ignoreUnreachable;
    }

    public static function fromConfig(Config $config, VersionParser $versionParser): ?self
    {
        $filterConfig = $config->get('filter');
        if (($filterConfig ?? false) === false) {
            return null;
        }

        $sources = [];
        $dontFilterPackages = [];
        if (\is_array($filterConfig)) {
            $dontFilterPackages = array_map(function ($packageConfig) use ($versionParser) {
                return DontFilterPackage::fromConfig($packageConfig, $versionParser);
            }, array_values($filterConfig['dont-filter-packages'] ?? []));

            foreach ($filterConfig['sources'] ?? [] as $sourceName => $source) {
                if (is_array($source) && isset($source['type']) && $source['type'] === 'url') {
                    $sources[] = new UrlSource($sourceName, $source['url']);
                }
            }
        }

        return new self(
            $dontFilterPackages,
            $sources,
            (bool) ($config->get('ignore-unreachable') ?? false)
        );
    }

    /**
     * @param 'audit'|'block' $operation
     */
    public function getOperationConfig(string $operation): self
    {
        $dontFilterPackages = [];
        foreach ($this->dontFilterPackages as $dontFilterPackage) {
            if (\in_array($dontFilterPackage->apply, ['all', $operation], true)) {
                $dontFilterPackages[] = $dontFilterPackage;
            }
        }

        return new self($dontFilterPackages, $this->sources, $this->ignoreUnreachable);
    }

    /**
     * @return array<string, DontFilterPackage>
     */
    public function getDontFilterPackageMap(): array
    {
        $map = [];
        foreach ($this->dontFilterPackages as $dontFilterPackage) {
            $map[$dontFilterPackage->packageName] = $dontFilterPackage;
        }

        return $map;
    }
}
