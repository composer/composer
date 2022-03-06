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

namespace Composer\Package;

use Composer\Semver\Constraint\ConstraintInterface;

/**
 * Represents a link between two packages, represented by their names
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class Link
{
    public const TYPE_REQUIRE = 'requires';
    public const TYPE_DEV_REQUIRE = 'devRequires';
    public const TYPE_PROVIDE = 'provides';
    public const TYPE_CONFLICT = 'conflicts';
    public const TYPE_REPLACE = 'replaces';

    /**
     * Special type
     * @internal
     */
    public const TYPE_DOES_NOT_REQUIRE = 'does not require';

    private const TYPE_UNKNOWN = 'relates to';

    /**
     * Will be converted into a constant once the min PHP version allows this
     *
     * @internal
     * @var string[]
     * @phpstan-var array<self::TYPE_REQUIRE|self::TYPE_DEV_REQUIRE|self::TYPE_PROVIDE|self::TYPE_CONFLICT|self::TYPE_REPLACE>
     */
    public static $TYPES = array(
        self::TYPE_REQUIRE,
        self::TYPE_DEV_REQUIRE,
        self::TYPE_PROVIDE,
        self::TYPE_CONFLICT,
        self::TYPE_REPLACE,
    );

    /**
     * @var string
     */
    protected $source;

    /**
     * @var string
     */
    protected $target;

    /**
     * @var ConstraintInterface
     */
    protected $constraint;

    /**
     * @var string
     * @phpstan-var string $description
     */
    protected $description;

    /**
     * @var ?string
     */
    protected $prettyConstraint;

    /**
     * Creates a new package link.
     *
     * @param string              $source
     * @param string              $target
     * @param ConstraintInterface $constraint       Constraint applying to the target of this link
     * @param self::TYPE_*        $description      Used to create a descriptive string representation
     * @param string|null         $prettyConstraint
     */
    public function __construct(
        string $source,
        string $target,
        ConstraintInterface $constraint,
        $description = self::TYPE_UNKNOWN,
        ?string $prettyConstraint = null
    ) {
        $this->source = strtolower($source);
        $this->target = strtolower($target);
        $this->constraint = $constraint;
        $this->description = self::TYPE_DEV_REQUIRE === $description ? 'requires (for development)' : $description;
        $this->prettyConstraint = $prettyConstraint;
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @return string
     */
    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * @return string
     */
    public function getTarget(): string
    {
        return $this->target;
    }

    /**
     * @return ConstraintInterface
     */
    public function getConstraint(): ConstraintInterface
    {
        return $this->constraint;
    }

    /**
     * @throws \UnexpectedValueException If no pretty constraint was provided
     * @return string
     */
    public function getPrettyConstraint(): string
    {
        if (null === $this->prettyConstraint) {
            throw new \UnexpectedValueException(sprintf('Link %s has been misconfigured and had no prettyConstraint given.', $this));
        }

        return $this->prettyConstraint;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return $this->source.' '.$this->description.' '.$this->target.' ('.$this->constraint.')';
    }

    /**
     * @param  PackageInterface $sourcePackage
     * @return string
     */
    public function getPrettyString(PackageInterface $sourcePackage): string
    {
        return $sourcePackage->getPrettyString().' '.$this->description.' '.$this->target.' '.$this->constraint->getPrettyString();
    }
}
