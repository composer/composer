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
     * @var bool|array<string, mixed>
     */
    private $config;

    /**
     * @var VersionParser
     */
    private $versionParser;

    /**
     * @param bool|array<string, mixed> $config
     */
    public function __construct(VersionParser $versionParser, $config)
    {
        $this->versionParser = $versionParser;
        $this->config = $config;
    }

    public static function fromConfig(Config $config, VersionParser $versionParser): ?self
    {
        $filterConfig = $config->get('filter');
        if (($filterConfig ?? false) === false) {
            return null;
        }

        return new self($versionParser, $filterConfig);
    }

    /**
     * @param 'audit'|'block' $operation
     */
    public function getListConfig(string $list, string $operation): ?ListConfig
    {
        $config = new ListConfig($this->versionParser);
        if ($this->config === true) {
            return $config;
        }

        // Config looks invalid, skip feature
        if (!\is_array($this->config)) {
            return null;
        }

        $config = $config->apply($this->config, $operation);
        if ($config === null) {
            return null;
        }

        if (\in_array($list, $this->config['exclude-lists'] ?? [], true)) {
            return null;
        }

        if (!isset($this->config['lists']) || \in_array($list, $this->config['lists'], true)) {
            return $config;
        }

        $matchingListConfig = null;
        foreach ($this->config['lists'] as $listConfig) {
            if (\is_array($listConfig) && ($listConfig['name'] ?? '') === $list) {
                $matchingListConfig = $listConfig;
            }
        }

        if (!\is_array($matchingListConfig)) {
            return null;
        }

        return $config->apply($matchingListConfig, $operation);
    }

    /**
     * @param 'audit'|'block' $operation
     * @return list<string>
     */
    public function getConfiguredListNames(string $operation): array
    {
        $listNames = [];
        foreach ($this->config['lists'] ?? [] as $listConfig) {
            $name = (string) (is_array($listConfig) ? $listConfig['name'] ?? '' : $listConfig);
            $list = $this->getListConfig($name, $operation);
            if ($list !== null) {
                $listNames[] = $name;
            }
        }

        return $listNames;
    }

    /**
     * @return list<UrlSource>
     */
    public function getSources(): array
    {
        $sources = [];
        foreach ($this->config['sources'] ?? [] as $sourceName => $source) {
            if (is_array($source) && isset($source['type']) && $source['type'] === 'url') {
                $sources[] = new UrlSource($sourceName, $source['url']);
            }
        }

        return $sources;
    }

    public function ignoreUnreachable(): bool
    {
        return (bool) ($this->config['ignore-unreachable'] ?? false);
    }
}
