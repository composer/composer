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

        if ('<>' === $operator) {
            $operator = '!=';
        }

        $this->operator = $operator;
        $this->version = $version;
    }

    public function versionCompare($a, $b, $operator, $compareBranches = false)
    {
        $aIsBranch = 'dev-' === substr($a, 0, 4);
        $bIsBranch = 'dev-' === substr($b, 0, 4);
        if ($aIsBranch && $bIsBranch) {
            return $operator == '==' && $a === $b;
        }

        // when branches are not comparable, we make sure dev branches never match anything
        if (!$compareBranches && ($aIsBranch || $bIsBranch)) {
            return false;
        }

        return version_compare($a, $b, $operator);
    }

    /**
     * @param  VersionConstraint $provider
     * @param  bool              $compareBranches
     * @return bool
     */
    public function matchSpecific(VersionConstraint $provider, $compareBranches = false)
    {
        static $cache = array();
        if (isset($cache[$this->operator][$this->version][$provider->operator][$provider->version][$compareBranches])) {
            return $cache[$this->operator][$this->version][$provider->operator][$provider->version][$compareBranches];
        }

        return $cache[$this->operator][$this->version][$provider->operator][$provider->version][$compareBranches] =
            $this->doMatchSpecific($provider, $compareBranches);
    }

    /**
     * @param  VersionConstraint $provider
     * @param  bool              $compareBranches
     * @return bool
     */
    private function doMatchSpecific(VersionConstraint $provider, $compareBranches = false)
    {
        $noEqualOp = str_replace('=', '', $this->operator);
        $providerNoEqualOp = str_replace('=', '', $provider->operator);

        $isEqualOp = '==' === $this->operator;
        $isNonEqualOp = '!=' === $this->operator;
        $isProviderEqualOp = '==' === $provider->operator;
        $isProviderNonEqualOp = '!=' === $provider->operator;

        // '!=' operator is match when other operator is not '==' operator or version is not match
        // these kinds of comparisons always have a solution
        if ($isNonEqualOp || $isProviderNonEqualOp) {
            return !$isEqualOp && !$isProviderEqualOp
                || $this->versionCompare($provider->version, $this->version, '!=', $compareBranches);
        }

        // an example for the condition is <= 2.0 & < 1.0
        // these kinds of comparisons always have a solution
        if ($this->operator != '==' && $noEqualOp == $providerNoEqualOp) {
            return true;
        }

        if ($this->versionCompare($provider->version, $this->version, $this->operator, $compareBranches)) {
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
