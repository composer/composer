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
 * Constrains a package link based on package version
 *
 * Version numbers must be compatible with version_compare
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class VersionConstraint extends SpecificConstraint
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

    public function matchSpecific(VersionConstraint $provider)
    {
        return version_compare($provider->version, $this->version, $this->operator);
    }

    public function __toString()
    {
        return $this->operator.' '.$this->version;
    }
}
