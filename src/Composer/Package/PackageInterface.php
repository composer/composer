<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Package;

use Composer\DependencyResolver\RelationConstraint\RelationConstraintInterface;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
interface PackageInterface
{
    /**
     * Returns the package's name without version info, thus not a unique identifier
     *
     * @return string package name
     */
    function getName();

    /**
     * Returns a set of names that could refer to this package
     *
     * No version or release type information should be included in any of the
     * names. Provided or replaced package names need to be returned as well.
     *
     * @return array An array of strings refering to this package
     */
    function getNames();

    /**
     * Checks if the package matches the given constraint directly or through
     * provided or replaced packages
     *
     * @param string                      $name       Name of the package to be matched
     * @param RelationConstraintInterface $constraint The constraint to verify
     * @return bool                                   Whether this package matches the name and constraint
     */
    function matches($name, RelationConstraintInterface $constraint);

    /**
     * Returns the release type of this package, e.g. stable or beta
     *
     * @return string The release type
     */
    function getReleaseType();

    /**
     * Returns the version of this package
     *
     * @return string version
     */
    function getVersion();

    /**
     * Returns a set of relations to packages which need to be installed before
     * this package can be installed
     *
     * @return array An array of package relations defining required packages
     */
    function getRequires();

    /**
     * Returns a set of relations to packages which must not be installed at the
     * same time as this package
     *
     * @return array An array of package relations defining conflicting packages
     */
    function getConflicts();

    /**
     * Returns a set of relations to virtual packages that are provided through
     * this package
     *
     * @return array An array of package relations defining provided packages
     */
    function getProvides();

    /**
     * Returns a set of relations to packages which can alternatively be
     * satisfied by installing this package
     *
     * @return array An array of package relations defining replaced packages
     */
    function getReplaces();

    /**
     * Returns a set of relations to packages which are recommended in
     * combination with this package.
     *
     * @return array An array of package relations defining recommended packages
     */
    function getRecommends();

    /**
     * Returns a set of relations to packages which are suggested in combination
     * with this package.
     *
     * @return array An array of package relations defining suggested packages
     */
    function getSuggests();

    /**
     * Converts the package into a readable and unique string
     *
     * @return string
     */
    function __toString();
}
