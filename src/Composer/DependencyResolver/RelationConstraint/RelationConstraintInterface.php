<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\DependencyResolver\RelationConstraint;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
interface RelationConstraintInterface
{
    function matches($releaseType, $version);
    function __toString();
}
