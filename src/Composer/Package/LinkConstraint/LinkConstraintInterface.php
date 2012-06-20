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
 * Defines a constraint on a link between two packages.
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
interface LinkConstraintInterface
{
    public function matches(LinkConstraintInterface $provider);
    public function setPrettyString($prettyString);
    public function getPrettyString();
    public function __toString();
}
