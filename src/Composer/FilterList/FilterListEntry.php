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

class FilterListEntry
{
    /**
     * @var string
     * @readonly
     */
    public $packageName;

    /**
     * @var string
     * @readonly
     */
    public $listName;

    /**
     * @var ConstraintInterface
     * @readonly
     */
    public $constraint;

    /**
     * @var string|null
     * @readonly
     */
    public $url;

    /**
     * @var string
     * @readonly
     */
    public $category;

    /**
     * @var string|null
     * @readonly
     */
    public $reason;

    public function __construct(
        string $packageName,
        ConstraintInterface $constraint,
        string $listName,
        string $category,
        ?string $url = null,
        ?string $reason = null
    ) {
        $this->packageName = $packageName;
        $this->listName = $listName;
        $this->constraint = $constraint;
        $this->url = $url;
        $this->category = $category;
        $this->reason = $reason;
    }

    /**
     * @param array{package: string, constraint: string, url?: string, category: string, reason?: string} $data
     */
    public static function create(string $listName, array $data, VersionParser $parser): self
    {
        $constraint = $parser->parseConstraints($data['constraint']);

        return new self(
            $data['package'],
            $constraint,
            $listName,
            $data['category'],
            $data['url'] ?? null,
            $data['reason'] ?? null
        );
    }
}
