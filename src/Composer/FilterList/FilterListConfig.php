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
use Composer\Semver\VersionParser;

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
        $ignoreUnreachable = false;
        if (\is_array($filterConfig)) {
            $unfilteredPackages = array_map(function ($packageConfig) use ($versionParser) {
                return UnfilteredPackage::fromConfig($packageConfig, $versionParser);
            }, array_values($filterConfig['unfiltered-packages'] ?? []));

            foreach ($filterConfig['sources'] ?? [] as $sourceName => $source) {
                if (is_array($source) && isset($source['type']) && $source['type'] === 'url') {
                    if (!isset($source['url']) || strpos($source['url'], 'https://') === false) {
                        throw new \RuntimeException('Invalid source config. "url" is required and must start with "https://".');
                    }

                    $sources[] = new UrlSource($sourceName, $source['url']);
                }
            }

            $ignoreUnreachable = (bool) ($filterConfig['ignore-unreachable'] ?? false);
        }

        return new self(
            $unfilteredPackages,
            $sources,
            $ignoreUnreachable
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
}
