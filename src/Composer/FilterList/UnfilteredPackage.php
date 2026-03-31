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

use Composer\Package\Version\VersionParser;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\MatchAllConstraint;

/**
 * @readonly
 * @internal
 * @final
 */
class UnfilteredPackage
{
    /** @var string */
    public $packageName;
    /** @var ConstraintInterface */
    public $constraint;
    /** @var string|null */
    public $reason;
    /** @var 'all'|'block'|'audit' */
    public $apply;

    /**
     * @param 'all'|'block'|'audit' $apply
     */
    private function __construct(
        string $packageName,
        ConstraintInterface $constraint,
        ?string $reason = null,
        string $apply = 'all'
    ) {
        $this->packageName = $packageName;
        $this->constraint = $constraint;
        $this->reason = $reason;
        $this->apply = $apply;
    }

    /**
     * @param array<mixed>|string|UnfilteredPackage $config
     */
    public static function fromConfig($config, VersionParser $parser): self
    {
        if ($config instanceof self) {
            return $config;
        }

        if (\is_string($config)) {
            return new self($config, new MatchAllConstraint());
        }

        if (\is_array($config) && \count($config) === 1 && !isset($config['package']) && !isset($config['constraint'])) {
            return new self(\key($config), $parser->parseConstraints((string) \array_pop($config)));
        }

        if (!isset($config['package'], $config['constraint'])) {
            throw new \RuntimeException('Invalid unfiltered package config. "package" and "constraint" are requried.');
        }

        return new self($config['package'], $parser->parseConstraints($config['constraint']), $config['reason'] ?? null, $config['apply'] ?? 'all');
    }
}
