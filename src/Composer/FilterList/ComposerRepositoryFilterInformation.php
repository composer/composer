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
class ComposerRepositoryFilterInformation
{
    /**
     * @var bool
     */
    public $metadata;

    /**
     * @var list<string>
     */
    public $lists;

    /**
     * @var list<string>
     */
    public $defaultLists;

    /**
     * @param list<string> $lists
     * @param list<string> $defaultLists
     */
    private function __construct(bool $metadata, array $lists, array $defaultLists)
    {
        $this->metadata = $metadata;
        $this->lists = $lists;
        $this->defaultLists = $defaultLists;
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromData(array $data): self
    {
        return new self(
            (bool) ($data['metadata'] ?? false),
            isset($data['lists']) && is_array($data['lists']) ? array_values($data['lists']) : [],
            isset($data['default-lists']) && is_array($data['default-lists']) ? array_values($data['default-lists']) : []
        );
    }
}
