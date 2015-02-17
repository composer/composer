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

/**
 * Defines additional fields that are only needed for the root package
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
interface RootPackageInterface extends CompletePackageInterface
{
    /**
     * Returns a set of package names and their aliases
     *
     * @return array
     */
    public function getAliases();

    /**
     * Returns the minimum stability of the package
     *
     * @return string
     */
    public function getMinimumStability();

    /**
     * Returns the stability flags to apply to dependencies
     *
     * array('foo/bar' => 'dev')
     *
     * @return array
     */
    public function getStabilityFlags();

    /**
     * Returns a set of package names and source references that must be enforced on them
     *
     * array('foo/bar' => 'abcd1234')
     *
     * @return array
     */
    public function getReferences();

    /**
     * Returns true if the root package prefers picking stable packages over unstable ones
     *
     * @return bool
     */
    public function getPreferStable();

    /**
     * Set the required packages
     *
     * @param array $requires A set of package links
     */
    public function setRequires(array $requires);

    /**
     * Set the recommended packages
     *
     * @param array $devRequires A set of package links
     */
    public function setDevRequires(array $devRequires);
}
