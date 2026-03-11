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
     * @return list<string>
     */
    public function getConfiguredListNames(): array
    {
        return array_values(array_filter(array_map(function ($list) {
            if (is_array($list)) {
                return (string) ($list['name'] ?? '');
            }

            return (string) $list;
        }, $this->config['lists'] ?? []), function ($name) {
            return $name !== '';
        }));
    }
}
