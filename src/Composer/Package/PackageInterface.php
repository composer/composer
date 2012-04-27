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
     * Returns whether the package is a development virtual package or a concrete one
     *
     * @return Boolean
     */
    function isDev();

    /**
     * Returns the package type, e.g. library
     *
     * @return string The package type
     */
    function getType();

    /**
     * Returns the package targetDir property
     *
     * @return string The package targetDir
     */
    function getTargetDir();

    /**
     * Returns the package extra data
     *
     * @return array The package extra data
     */
    function getExtra();

    /**
     * Sets source from which this package was installed (source/dist).
     *
     * @param   string  $type   source/dist
     */
    function setInstallationSource($type);

    /**
     * Returns source from which this package was installed (source/dist).
     *
     * @param   string  $type   source/dist
     */
    function getInstallationSource();

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
     * Returns the repository reference of this package, e.g. master, 1.0.0 or a commit hash for git
     *
     * @return string The repository reference
     */
    function getSourceReference();

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
     * Returns the reference of the distribution archive of this version, e.g. master, 1.0.0 or a commit hash for git
     *
     * @return string
     */
    function getDistReference();

    /**
     * Returns the sha1 checksum for the distribution archive of this version
     *
     * @return string
     */
    function getDistSha1Checksum();

    /**
     * Returns the scripts of this package
     *
     * @return array array('script name' => array('listeners'))
     */
    function getScripts();

    /**
     * Returns the version of this package
     *
     * @return string version
     */
    function getVersion();

    /**
     * Returns the pretty (i.e. non-normalized) version string of this package
     *
     * @return string version
     */
    function getPrettyVersion();

    /**
     * Returns the package license, e.g. MIT, BSD, GPL
     *
     * @return array The package licenses
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
     * Returns a set of links to packages which are required to develop
     * this package. These are installed if in dev mode.
     *
     * @return array An array of package links defining packages required for development
     */
    function getDevRequires();

    /**
     * Returns a set of package names and reasons why they are useful in
     * combination with this package.
     *
     * @return array An array of package suggestions with descriptions
     */
    function getSuggests();

    /**
     * Returns an associative array of autoloading rules
     *
     * {"<type>": {"<namespace": "<directory>"}}
     *
     * Type is either "psr-0" or "pear". Namespaces are mapped to directories
     * for autoloading using the type specified.
     *
     * @return array Mapping of autoloading rules
     */
    function getAutoload();

    /**
     * Returns a list of directories which should get added to PHP's
     * include path.
     *
     * @return array
     */
    function getIncludePaths();

    /**
     * Returns an array of repositories
     *
     * {"<type>": {<config key/values>}}
     *
     * @return array Repositories
     */
    function getRepositories();

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
     * Returns the release date of the package
     *
     * @return DateTime
     */
    function getReleaseDate();

    /**
     * Returns an array of keywords relating to the package
     *
     * @return array
     */
    function getKeywords();

    /**
     * Returns the package description
     *
     * @return string
     */
    function getDescription();

    /**
     * Returns the package binaries
     *
     * @return array
     */
    function getBinaries();

    /**
     * Returns the package homepage
     *
     * @return string
     */
    function getHomepage();

    /**
     * Returns an array of authors of the package
     *
     * Each item can contain name/homepage/email keys
     *
     * @return array
     */
    function getAuthors();

    /**
     * Returns a version this package should be aliased to
     *
     * @return string
     */
    function getAlias();

    /**
     * Returns a non-normalized version this package should be aliased to
     *
     * @return string
     */
    function getPrettyAlias();

    /**
     * Returns package unique name, constructed from name and version.
     *
     * @return string
     */
    function getUniqueName();

    /**
     * Converts the package into a readable and unique string
     *
     * @return string
     */
    function __toString();
}
