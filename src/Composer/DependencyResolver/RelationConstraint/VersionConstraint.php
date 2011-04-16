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

namespace Composer\DependencyResolver\RelationConstraint;

/**
 * Constrains a package relation based on package version
 *
 * Version numbers must be compatible with version_compare
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class VersionConstraint implements RelationConstraintInterface
{
    private $operator;
    private $version;

    /**
     * Sets operator and version to compare a package with
     *
     * @param string $operator A comparison operator
     * @param string $version  A version to compare to
     */
    public function __construct($operator, $version)
    {
        $this->operator = $operator;
        $this->version = $version;
    }

    public function matches($releaseType, $version)
    {
        return version_compare($version, $this->version, $this->operator);
    }

    public function __toString()
    {
        return $this->operator.' '.$this->version;
    }
}
