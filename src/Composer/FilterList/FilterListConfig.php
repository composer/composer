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
     * @var list<UnfilteredPackage>
     */
    public $unfilteredPackages;

    /**
     * @var list<UrlSource>
     */
    public $sources;

    /**
     * @var bool
     */
    public $ignoreUnreachable;

    /**
     * @param list<UnfilteredPackage> $unfilteredPackages
     * @param list<UrlSource> $sources
     */
    public function __construct(array $unfilteredPackages, array $sources, bool $ignoreUnreachable)
    {
        $this->unfilteredPackages = $unfilteredPackages;
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
        $unfilteredPackages = [];
        if (\is_array($filterConfig)) {
            $unfilteredPackages = array_map(function ($packageConfig) use ($versionParser) {
                return UnfilteredPackage::fromConfig($packageConfig, $versionParser);
            }, array_values($filterConfig['unfiltered-packages'] ?? []));

            foreach ($filterConfig['sources'] ?? [] as $sourceName => $source) {
                if (is_array($source) && isset($source['type']) && $source['type'] === 'url') {
                    $sources[] = new UrlSource($sourceName, $source['url']);
                }
            }
        }

        return new self(
            $unfilteredPackages,
            $sources,
            (bool) ($config->get('ignore-unreachable') ?? false)
        );
    }

    /**
     * @param 'audit'|'block' $operation
     */
    public function getOperationConfig(string $operation): self
    {
        $unfilteredPackages = [];
        foreach ($this->unfilteredPackages as $unfilteredPackage) {
            if (\in_array($unfilteredPackage->apply, ['all', $operation], true)) {
                $unfilteredPackages[] = $unfilteredPackage;
            }
        }

        return new self($unfilteredPackages, $this->sources, $this->ignoreUnreachable);
    }

    /**
     * @return array<string, UnfilteredPackage>
     */
    public function getUnfilteredPackageMap(): array
    {
        $map = [];
        foreach ($this->unfilteredPackages as $unfilteredPackage) {
            $map[$unfilteredPackage->packageName] = $unfilteredPackage;
        }

        return $map;
    }
}
