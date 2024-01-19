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

namespace Composer\Advisory;

use Composer\Semver\Constraint\ConstraintInterface;
use DateTimeImmutable;

class SecurityAdvisory extends PartialSecurityAdvisory
{
    /**
     * @var string
     * @readonly
     */
    public $title;

    /**
     * @var string|null
     * @readonly
     */
    public $cve;

    /**
     * @var string|null
     * @readonly
     */
    public $link;

    /**
     * @var DateTimeImmutable
     * @readonly
     */
    public $reportedAt;

    /**
     * @var non-empty-array<array{name: string, remoteId: string}>
     * @readonly
     */
    public $sources;

    /**
     * @var string|null
     * @readonly
     */
    public $severity;

    /**
     * @param non-empty-array<array{name: string, remoteId: string}> $sources
     */
    public function __construct(string $packageName, string $advisoryId, ConstraintInterface $affectedVersions, string $title, array $sources, DateTimeImmutable $reportedAt, ?string $cve = null, ?string $link = null, ?string $severity = null)
    {
        parent::__construct($packageName, $advisoryId, $affectedVersions);

        $this->title = $title;
        $this->sources = $sources;
        $this->reportedAt = $reportedAt;
        $this->cve = $cve;
        $this->link = $link;
        $this->severity = $severity;
    }

    /**
     * @internal
     */
    public function toIgnoredAdvisory(?string $ignoreReason): IgnoredSecurityAdvisory
    {
        return new IgnoredSecurityAdvisory(
            $this->packageName,
            $this->advisoryId,
            $this->affectedVersions,
            $this->title,
            $this->sources,
            $this->reportedAt,
            $this->cve,
            $this->link,
            $ignoreReason,
            $this->severity
        );
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        $data = parent::jsonSerialize();
        $data['reportedAt'] = $data['reportedAt']->format(DATE_RFC3339);

        return $data;
    }
}
