<?php

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
    const TYPE_REQUIRE = 'requires';
    const TYPE_DEV_REQUIRE = 'devRequires';
    const TYPE_PROVIDE = 'provides';
    const TYPE_CONFLICT = 'conflicts';
    const TYPE_REPLACE = 'replaces';
    /**
     * TODO should be marked private once 5.3 is dropped
     * @private
     */
    const TYPE_UNKNOWN = 'relates to';

    /**
     * Will be converted into a constant once the min PHP version allows this
     *
     * @internal
     * @var string[]
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
     * @phpstan-var self::TYPE_* $description
     */
    protected $description;

    /**
     * @var string|null
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
        $source,
        $target,
        ConstraintInterface $constraint,
        $description = self::TYPE_UNKNOWN,
        $prettyConstraint = null
    ) {
        $this->source = strtolower($source);
        $this->target = strtolower($target);
        $this->constraint = $constraint;
        $this->description = $description;
        $this->prettyConstraint = $prettyConstraint;
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * @return string
     */
    public function getSource()
    {
        return $this->source;
    }

    /**
     * @return string
     */
    public function getTarget()
    {
        return $this->target;
    }

    /**
     * @return ConstraintInterface
     */
    public function getConstraint()
    {
        return $this->constraint;
    }

    /**
     * @throws \UnexpectedValueException If no pretty constraint was provided
     * @return string
     */
    public function getPrettyConstraint()
    {
        if (null === $this->prettyConstraint) {
            throw new \UnexpectedValueException(sprintf('Link %s has been misconfigured and had no prettyConstraint given.', $this));
        }

        return $this->prettyConstraint;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->source.' '.$this->description.' '.$this->target.' ('.$this->constraint.')';
    }

    /**
     * @param  PackageInterface $sourcePackage
     * @return string
     */
    public function getPrettyString(PackageInterface $sourcePackage)
    {
        return $sourcePackage->getPrettyString().' '.$this->description.' '.$this->target.($this->constraint ? ' '.$this->constraint->getPrettyString() : '');
    }
}
