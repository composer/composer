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

use Composer\Pcre\Preg;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;
use JsonSerializable;

class PartialSecurityAdvisory implements JsonSerializable
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
        try {
            $constraint = $parser->parseConstraints($data['affectedVersions']);
        } catch (\UnexpectedValueException $e) {
            // try to keep only the essential part of the constraint to turn invalid ones like <=3.20-test2 into <=3.20 which is better than nothing
            try {
                $affectedVersion = Preg::replace('{(^[>=<^~]*[\d.]+).*}', '$1', $data['affectedVersions']);
                $constraint = $parser->parseConstraints($affectedVersion);;
            } catch (\UnexpectedValueException $e) {
                $constraint = new Constraint('==', '0.0.0-invalid-version');
            }
        }

        if (isset($data['title'], $data['sources'], $data['reportedAt'])) {
            return new SecurityAdvisory($packageName, $data['advisoryId'], $constraint, $data['title'], $data['sources'], new \DateTimeImmutable($data['reportedAt'], new \DateTimeZone('UTC')), $data['cve'] ?? null, $data['link'] ?? null, $data['severity'] ?? null);
        }

        return new self($packageName, $data['advisoryId'], $constraint);
    }

    public function __construct(string $packageName, string $advisoryId, ConstraintInterface $affectedVersions)
    {
        $this->advisoryId = $advisoryId;
        $this->packageName = $packageName;
        $this->affectedVersions = $affectedVersions;
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        $data = (array) $this;
        $data['affectedVersions'] = $data['affectedVersions']->getPrettyString();

        return $data;
    }
}
