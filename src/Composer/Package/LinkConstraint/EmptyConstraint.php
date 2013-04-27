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
 * Defines an absence of constraints
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class EmptyConstraint implements LinkConstraintInterface
{
    protected $prettyString;

    public function matches(LinkConstraintInterface $provider)
    {
        return true;
    }

    public function setPrettyString($prettyString)
    {
        $this->prettyString = $prettyString;
    }

    public function getPrettyString()
    {
        if ($this->prettyString) {
            return $this->prettyString;
        }

        return $this->__toString();
    }

    public function __toString()
    {
        return '[]';
    }
}
