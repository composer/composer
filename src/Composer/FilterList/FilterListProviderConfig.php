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
class FilterListProviderConfig
{
    /**
     * @var list<ListConfig>
     */
    private $lists;

    /**
     * @var list<string>
     */
    private $defaultListNames;

    /**
     * @param list<ListConfig> $lists
     * @param list<string> $defaultListNames
     */
    private function __construct(array $lists, array $defaultListNames)
    {
        $this->lists = $lists;
        $this->defaultListNames = $defaultListNames;
    }

    /**
     * @param bool|array<mixed> $data
     * @param list<string> $defaultListNames
     */
    public static function fromConfig($data, array $defaultListNames): self
    {
        $lists = ['defaults'];
        if ($data === false) {
            $lists = [];
        }

        if (is_array($data)) {
            $lists = $data['lists'] ?? $lists;
        }

        $lists = array_map(function ($list) use ($defaultListNames) {
            return ListConfig::fromConfig($list, $defaultListNames);
        }, array_values($lists));

        return new self($lists, $defaultListNames);
    }

    /**
     * @param 'block'|'audit' $operation
     * @return array<string, ListConfig>
     */
    public function getListsForOperation(string $operation): array
    {
        $lists = [];
        foreach ($this->lists as $item) {
            if (in_array($item->only, ['all', $operation], true)) {
                foreach ($item->expandDefaults($this->defaultListNames) as $expanded) {
                    if ($expanded->exclude) {
                        unset($lists[$expanded->name]);
                    } else {
                        $lists[$expanded->name] = $expanded;
                    }
                }
            }
        }

        return $lists;
    }
}
