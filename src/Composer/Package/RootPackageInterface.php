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

use Composer\Package\LinkConstraint\LinkConstraintInterface;
use Composer\Repository\RepositoryInterface;

/**
 * Defines additional fields that are only needed for the root package
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
interface RootPackageInterface extends PackageInterface
{
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
}
