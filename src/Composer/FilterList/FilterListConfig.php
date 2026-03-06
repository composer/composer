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
class FilterListConfig
{
    /**
     * @var bool|array<string, mixed>
     */
    private $config;

    /**
     * @param bool|array<string, mixed> $config
     */
    public function __construct($config)
    {
        $this->config = $config;
    }

    /**
     * @param 'audit'|'block' $operation
     */
    public function getListConfig(string $list, string $operation): ?ListConfig
    {
        $config = new ListConfig();
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
}
