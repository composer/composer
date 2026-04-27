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

use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;

/**
 * @readonly
 */
class FilterListEntry
{
    /**
     * @var string
     */
    public $packageName;

    /**
     * @var string
     */
    public $listName;

    /**
     * @var ConstraintInterface
     */
    public $constraint;

    /**
     * @var string|null
     */
    public $url;

    /**
     * @var string|null
     */
    public $reason;

    /**
     * @var string|null
     */
    public $id;

    /**
     * @var string|null
     */
    public $source;

    public function __construct(
        string $packageName,
        ConstraintInterface $constraint,
        string $listName,
        ?string $url = null,
        ?string $reason = null,
        ?string $id = null,
        ?string $source = null
    ) {
        $this->packageName = $packageName;
        $this->listName = $listName;
        $this->constraint = $constraint;
        $this->url = $url;
        $this->reason = $reason;
        $this->id = $id;
        $this->source = $source;
    }

    /**
     * @param array{package: string, constraint: string, url?: string, reason?: string, id?: string, source?: ?string} $data
     */
    public static function create(string $listName, array $data, VersionParser $parser): self
    {
        $constraint = $parser->parseConstraints($data['constraint']);

        return new self(
            $data['package'],
            $constraint,
            $listName,
            $data['url'] ?? null,
            $data['reason'] ?? null,
            $data['id'] ?? null,
            $data['source'] ?? null
        );
    }
}
