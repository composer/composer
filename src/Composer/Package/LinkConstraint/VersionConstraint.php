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
        if ('=' === $operator) {
            $operator = '==';
        }

        $this->operator = $operator;
        $this->version = $version;
    }

    /**
     *
     * @param VersionConstraint $provider
     */
    public function matchSpecific(VersionConstraint $provider)
    {
        $noEqualOp = str_replace('=', '', $this->operator);
        $providerNoEqualOp = str_replace('=', '', $provider->operator);

        // an example for the condition is <= 2.0 & < 1.0
        // these kinds of comparisons always have a solution
        if ($this->operator != '==' && $noEqualOp == $providerNoEqualOp) {
            return true;
        }

        if (version_compare($provider->version, $this->version, $this->operator)) {
            // special case, e.g. require >= 1.0 and provide < 1.0
            // 1.0 >= 1.0 but 1.0 is outside of the provided interval
            if ($provider->version == $this->version && $provider->operator == $providerNoEqualOp && $this->operator != $noEqualOp) {
                return false;
            }

            return true;
        }

        return false;
    }

    public function __toString()
    {
        return $this->operator.' '.$this->version;
    }
}
