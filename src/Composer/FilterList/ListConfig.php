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
    /** @var string */
    public $name;
    /** @var string */
    public $reason;
    /** @var 'audit'|'block'|'all' */
    public $only;
    /** @var bool */
    public $exclude;
    /** @var bool */
    public $default;

    /**
     * @param 'audit'|'block'|'all' $only
     */
    public function __construct(
        string $name,
        string $only = 'all',
        string $reason = '',
        bool $default = false
    ) {
        $this->name = ltrim($name, '!');
        $this->only = $only;
        $this->reason = $reason;
        $this->exclude = strpos($name, '!') === 0;
        $this->default = $default;
    }

    /**
     * @param string|array{name: string, only?: string, reason?: string} $list
     * @param list<string> $defaultListNames
     */
    public static function fromConfig($list, array $defaultListNames = []): self
    {
        if (is_array($list) && !isset($list['name'])) {
            throw new \RuntimeException('Invalid list config. "name" is required.');
        }

        return new self(
            $name = is_array($list) ? $list['name'] : (string) $list,
            is_array($list) && isset($list['only']) && in_array($list['only'], ['audit', 'block', 'all'], true) ? $list['only'] : 'all',
            is_array($list) && isset($list['reason'])  ? $list['reason'] : '',
            in_array($name, $defaultListNames, true)
        );
    }

    /**
     * @param list<string> $lists
     * @return list<ListConfig>
     */
    public function expandDefaults(array $lists): array
    {
        if ($this->name !== 'defaults') {
            return [$this];
        }

        $expanded = [];
        foreach ($lists as $list) {
            $expanded[] = new self(
                ($this->exclude ? '!' : '') . $list,
                $this->only,
                $this->reason,
                true
            );
        }

        return $expanded;
    }
}
