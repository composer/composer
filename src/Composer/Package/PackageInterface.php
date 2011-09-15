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
     * Returns the package's pretty (i.e. with proper case) name
     *
     * @return string package name
     */
    function getPrettyName();

    /**
     * Returns a set of names that could refer to this package
     *
     * No version or release type information should be included in any of the
     * names. Provided or replaced package names need to be returned as well.
     *
     * @return array An array of strings referring to this package
     */
    function getNames();

    /**
     * Allows the solver to set an id for this package to refer to it.
     *
     * @param int $id
     */
    function setId($id);

    /**
     * Retrieves the package's id set through setId
     *
     * @return int The previously set package id
     */
    function getId();

    /**
     * Checks if the package matches the given constraint directly or through
     * provided or replaced packages
     *
     * @param string                  $name       Name of the package to be matched
     * @param LinkConstraintInterface $constraint The constraint to verify
     * @return bool                               Whether this package matches the name and constraint
     */
    function matches($name, LinkConstraintInterface $constraint);

    /**
     * Returns the package type, e.g. library
     *
     * @return string The package type
     */
    function getType();

    /**
     * Returns the package extra data
     *
     * @return array The package extra data
     */
    function getExtra();

    /**
     * Returns the repository type of this package, e.g. git, svn
     *
     * @return string The repository type
     */
    function getSourceType();

    /**
     * Returns the repository url of this package, e.g. git://github.com/naderman/composer.git
     *
     * @return string The repository url
     */
    function getSourceUrl();

    /**
     * Returns the type of the distribution archive of this version, e.g. zip, tarball
     *
     * @return string The repository type
     */
    function getDistType();

    /**
     * Returns the url of the distribution archive of this version
     *
     * @return string
     */
    function getDistUrl();

    /**
     * Returns the sha1 checksum for the distribution archive of this version
     *
     * @return string
     */
    function getDistSha1Checksum();

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
     * Returns the package license, e.g. MIT, BSD, GPL
     *
     * @return string The package license
     */
    function getLicense();

    /**
     * Returns a set of links to packages which need to be installed before
     * this package can be installed
     *
     * @return array An array of package links defining required packages
     */
    function getRequires();

    /**
     * Returns a set of links to packages which must not be installed at the
     * same time as this package
     *
     * @return array An array of package links defining conflicting packages
     */
    function getConflicts();

    /**
     * Returns a set of links to virtual packages that are provided through
     * this package
     *
     * @return array An array of package links defining provided packages
     */
    function getProvides();

    /**
     * Returns a set of links to packages which can alternatively be
     * satisfied by installing this package
     *
     * @return array An array of package links defining replaced packages
     */
    function getReplaces();

    /**
     * Returns a set of links to packages which are recommended in
     * combination with this package.
     *
     * @return array An array of package links defining recommended packages
     */
    function getRecommends();

    /**
     * Returns a set of links to packages which are suggested in combination
     * with this package.
     *
     * @return array An array of package links defining suggested packages
     */
    function getSuggests();

    /**
     * Stores a reference to the repository that owns the package
     *
     * @param RepositoryInterface $repository
     */
    function setRepository(RepositoryInterface $repository);

    /**
     * Returns a reference to the repository that owns the package
     *
     * @return RepositoryInterface
     */
    function getRepository();

    /**
     * Converts the package into a readable and unique string
     *
     * @return string
     */
    function __toString();
}
