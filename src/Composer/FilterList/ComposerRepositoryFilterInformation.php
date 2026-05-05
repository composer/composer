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

use Composer\Policy\PolicyConfig;

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
     * @param list<string> $lists
     */
    private function __construct(bool $metadata, array $lists)
    {
        $this->metadata = $metadata;
        $this->lists = $lists;
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromData(array $data): self
    {
        $lists = isset($data['lists']) && is_array($data['lists']) ? array_values($data['lists']) : [];
        $defaultLists = isset($data['default-lists']) && is_array($data['default-lists']) ? array_values($data['default-lists']) : [];

        // Repos must not advertise built-in list names or names that collide with
        // future-reserved identifiers; drop them silently so they cannot shadow
        // Composer's own advisory/abandoned handling or claim a future reserved slot.
        $lists = array_values(array_filter($lists, static function ($name): bool {
            if (in_array($name, PolicyConfig::RESERVED_NAMES, true) || in_array($name, PolicyConfig::FUTURE_RESERVED_NAMES, true)) {
                return false;
            }

            foreach (PolicyConfig::FUTURE_RESERVED_PREFIXES as $prefix) {
                if (is_string($name) && str_starts_with($name, $prefix)) {
                    return false;
                }
            }

            return true;
        }));

        return new self(
            (bool) ($data['metadata'] ?? false),
            $lists
        );
    }
}
