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

    /**
     * Creates a new package link.
     *
     * @param string                  $source
     * @param string                  $target
     * @param LinkConstraintInterface $constraint  Constraint applying to the target of this link
     * @param string                  $description Used to create a descriptive string representation
     */
    public function __construct($source, $target, LinkConstraintInterface $constraint = null, $description = 'relates to')
    {
        $this->source = $source;
        $this->target = $target;
        $this->constraint = $constraint;
        $this->description = $description;
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

    public function __toString()
    {
        return $this->source.' '.$this->description.' '.$this->target.' ('.$this->constraint.')';
    }
}
