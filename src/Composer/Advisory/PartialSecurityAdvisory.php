<?php declare(strict_types=1);

namespace Composer\Advisory;

use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;

class PartialSecurityAdvisory
{
    /**
     * @var string
     * @readonly
     */
    public $advisoryId;

    /**
     * @var string
     * @readonly
     */
    public $packageName;

    /**
     * @var ConstraintInterface
     * @readonly
     */
    public $affectedVersions;

    /**
     * @param array<mixed> $data
     * @return SecurityAdvisory|PartialSecurityAdvisory
     */
    public static function create(string $packageName, array $data, VersionParser $parser): self
    {
        $constraint = $parser->parseConstraints($data['affectedVersions']);
        if (isset($data['title'], $data['sources'], $data['reportedAt'])) {
            return new SecurityAdvisory($packageName, $data['advisoryId'], $constraint, $data['title'], $data['sources'], new \DateTimeImmutable($data['reportedAt'], new \DateTimeZone('UTC')), $data['cve'] ?? null, $data['link'] ?? null);
        }

        return new self($packageName, $data['advisoryId'], $constraint);
    }

    public function __construct(string $packageName, string $advisoryId, ConstraintInterface $affectedVersions)
    {
        $this->advisoryId = $advisoryId;
        $this->packageName = $packageName;
        $this->affectedVersions = $affectedVersions;
    }
}
