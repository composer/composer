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

namespace Composer\Package\LinkConstraint;

/**
 * Defines a conjunctive set of constraints on the target of a package link
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class MultiConstraint implements LinkConstraintInterface
{
    protected $constraints;

    /**
     * Sets operator and version to compare a package with
     *
     * @param array $constraints A conjunctive set of constraints
     */
    public function __construct(array $constraints)
    {
        $this->constraints = $constraints;
    }

    public function matches(LinkConstraintInterface $provider)
    {
        foreach ($this->constraints as $constraint) {
            if (!$constraint->matches($provider)) {
                return false;
            }
        }

        return true;
    }

    public function __toString()
    {
        $constraints = array();
        foreach ($this->constraints as $constraint) {
            $constraints[] = $constraint->__toString();
        }
        return '['.implode(', ', $constraints).']';
    }
}
