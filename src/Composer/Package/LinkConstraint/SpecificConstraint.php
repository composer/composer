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
 * Provides a common basis for specific package link constraints
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class SpecificConstraint implements LinkConstraintInterface
{
    public function matches(LinkConstraintInterface $provider)
    {
        if ($provider instanceof MultiConstraint) {
            // turn matching around to find a match
            return $provider->matches($this);
        } else if ($provider instanceof get_class($this)) {
            return $this->matchSpecific($provider);
        }

        return true;
    }

    abstract public function matchSpecific($provider);

    abstract public function __toString();
}
