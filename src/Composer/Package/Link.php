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

use Composer\Package\LinkConstraint\LinkConstraintInterface;

/**
 * Represents a link between two packages, represented by their names
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class Link
{
    protected $source;
    protected $target;
    protected $constraint;
    protected $description;
    protected $prettyConstraint;

    /**
     * Creates a new package link.
     *
     * @param string                  $source
     * @param string                  $target
     * @param LinkConstraintInterface $constraint       Constraint applying to the target of this link
     * @param string                  $description      Used to create a descriptive string representation
     * @param string                  $prettyConstraint
     */
    public function __construct($source, $target, LinkConstraintInterface $constraint = null, $description = 'relates to', $prettyConstraint = null)
    {
        $this->source = strtolower($source);
        $this->target = strtolower($target);
        $this->constraint = $constraint;
        $this->description = $description;
        $this->prettyConstraint = $prettyConstraint;
    }

    public function getSource()
    {
        return $this->source;
    }

    public function getTarget()
    {
        return $this->target;
    }

    public function getConstraint()
    {
        return $this->constraint;
    }

    public function getPrettyConstraint()
    {
        if (null === $this->prettyConstraint) {
            throw new \UnexpectedValueException(sprintf('Link %s has been misconfigured and had no prettyConstraint given.', $this));
        }

        return $this->prettyConstraint;
    }

    public function __toString()
    {
        return $this->source.' '.$this->description.' '.$this->target.' ('.$this->constraint.')';
    }

    public function getPrettyString(PackageInterface $sourcePackage)
    {
        return $sourcePackage->getPrettyString().' '.$this->description.' '.$this->target.' '.$this->constraint->getPrettyString().'';
    }
}
