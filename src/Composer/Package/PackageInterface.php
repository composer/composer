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

use Composer\Repository\RepositoryInterface;

/**
 * Defines the essential information a package has that is used during solving/installation
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
interface PackageInterface
{
    const DISPLAY_SOURCE_REF_IF_DEV = 0;
    const DISPLAY_SOURCE_REF = 1;
    const DISPLAY_DIST_REF = 2;

    /**
     * Returns the package's name without version info, thus not a unique identifier
     *
     * @return string package name
     */
    public function getName();

    /**
     * Returns the package's pretty (i.e. with proper case) name
     *
     * @return string package name
     */
    public function getPrettyName();

    /**
     * Returns a set of names that could refer to this package
     *
     * No version or release type information should be included in any of the
     * names. Provided or replaced package names need to be returned as well.
     *
     * @param bool $provides Whether provided names should be included
     *
     * @return string[] An array of strings referring to this package
     */
    public function getNames($provides = true);

    /**
     * Allows the solver to set an id for this package to refer to it.
     *
     * @param int $id
     *
     * @return void
     */
    public function setId($id);

    /**
     * Retrieves the package's id set through setId
     *
     * @return int The previously set package id
     */
    public function getId();

    /**
     * Returns whether the package is a development virtual package or a concrete one
     *
     * @return bool
     */
    public function isDev();

    /**
     * Returns the package type, e.g. library
     *
     * @return string The package type
     */
    public function getType();

    /**
     * Returns the package targetDir property
     *
     * @return ?string The package targetDir
     */
    public function getTargetDir();

    /**
     * Returns the package extra data
     *
     * @return mixed[] The package extra data
     */
    public function getExtra();

    /**
     * Sets source from which this package was installed (source/dist).
     *
     * @param string $type source/dist
     * @phpstan-param 'source'|'dist'|null $type
     *
     * @return void
     */
    public function setInstallationSource($type);

    /**
     * Returns source from which this package was installed (source/dist).
     *
     * @return ?string source/dist
     * @phpstan-return 'source'|'dist'|null
     */
    public function getInstallationSource();

    /**
     * Returns the repository type of this package, e.g. git, svn
     *
     * @return ?string The repository type
     */
    public function getSourceType();

    /**
     * Returns the repository url of this package, e.g. git://github.com/naderman/composer.git
     *
     * @return ?string The repository url
     */
    public function getSourceUrl();

    /**
     * Returns the repository urls of this package including mirrors, e.g. git://github.com/naderman/composer.git
     *
     * @return string[]
     */
    public function getSourceUrls();

    /**
     * Returns the repository reference of this package, e.g. master, 1.0.0 or a commit hash for git
     *
     * @return ?string The repository reference
     */
    public function getSourceReference();

    /**
     * Returns the source mirrors of this package
     *
     * @return ?array<int, array{url: string, preferred: bool}>
     */
    public function getSourceMirrors();

    /**
     * @param  ?array<int, array{url: string, preferred: bool}> $mirrors
     * @return void
     */
    public function setSourceMirrors($mirrors);

    /**
     * Returns the type of the distribution archive of this version, e.g. zip, tarball
     *
     * @return ?string The repository type
     */
    public function getDistType();

    /**
     * Returns the url of the distribution archive of this version
     *
     * @return ?string
     */
    public function getDistUrl();

    /**
     * Returns the urls of the distribution archive of this version, including mirrors
     *
     * @return string[]
     */
    public function getDistUrls();

    /**
     * Returns the reference of the distribution archive of this version, e.g. master, 1.0.0 or a commit hash for git
     *
     * @return ?string
     */
    public function getDistReference();

    /**
     * Returns the sha1 checksum for the distribution archive of this version
     *
     * @return ?string
     */
    public function getDistSha1Checksum();

    /**
     * Returns the dist mirrors of this package
     *
     * @return ?array<int, array{url: string, preferred: bool}>
     */
    public function getDistMirrors();

    /**
     * @param  ?array<int, array{url: string, preferred: bool}> $mirrors
     * @return void
     */
    public function setDistMirrors($mirrors);

    /**
     * Returns the version of this package
     *
     * @return string version
     */
    public function getVersion();

    /**
     * Returns the pretty (i.e. non-normalized) version string of this package
     *
     * @return string version
     */
    public function getPrettyVersion();

    /**
     * Returns the pretty version string plus a git or hg commit hash of this package
     *
     * @see getPrettyVersion
     *
     * @param  bool   $truncate    If the source reference is a sha1 hash, truncate it
     * @param  int    $displayMode One of the DISPLAY_ constants on this interface determining display of references
     * @return string version
     *
     * @phpstan-param self::DISPLAY_SOURCE_REF_IF_DEV|self::DISPLAY_SOURCE_REF|self::DISPLAY_DIST_REF $displayMode
     */
    public function getFullPrettyVersion($truncate = true, $displayMode = self::DISPLAY_SOURCE_REF_IF_DEV);

    /**
     * Returns the release date of the package
     *
     * @return ?\DateTime
     */
    public function getReleaseDate();

    /**
     * Returns the stability of this package: one of (dev, alpha, beta, RC, stable)
     *
     * @return string
     *
     * @phpstan-return 'stable'|'RC'|'beta'|'alpha'|'dev'
     */
    public function getStability();

    /**
     * Returns a set of links to packages which need to be installed before
     * this package can be installed
     *
     * @return array<string, Link> An array of package links defining required packages
     */
    public function getRequires();

    /**
     * Returns a set of links to packages which must not be installed at the
     * same time as this package
     *
     * @return Link[] An array of package links defining conflicting packages
     */
    public function getConflicts();

    /**
     * Returns a set of links to virtual packages that are provided through
     * this package
     *
     * @return Link[] An array of package links defining provided packages
     */
    public function getProvides();

    /**
     * Returns a set of links to packages which can alternatively be
     * satisfied by installing this package
     *
     * @return Link[] An array of package links defining replaced packages
     */
    public function getReplaces();

    /**
     * Returns a set of links to packages which are required to develop
     * this package. These are installed if in dev mode.
     *
     * @return array<string, Link> An array of package links defining packages required for development
     */
    public function getDevRequires();

    /**
     * Returns a set of package names and reasons why they are useful in
     * combination with this package.
     *
     * @return array An array of package suggestions with descriptions
     * @phpstan-return array<string, string>
     */
    public function getSuggests();

    /**
     * Returns an associative array of autoloading rules
     *
     * {"<type>": {"<namespace": "<directory>"}}
     *
     * Type is either "psr-4", "psr-0", "classmap" or "files". Namespaces are mapped to
     * directories for autoloading using the type specified.
     *
     * @return array Mapping of autoloading rules
     * @phpstan-return array{psr-0?: array<string, string|string[]>, psr-4?: array<string, string|string[]>, classmap?: list<string>, files?: list<string>}
     */
    public function getAutoload();

    /**
     * Returns an associative array of dev autoloading rules
     *
     * {"<type>": {"<namespace": "<directory>"}}
     *
     * Type is either "psr-4", "psr-0", "classmap" or "files". Namespaces are mapped to
     * directories for autoloading using the type specified.
     *
     * @return array Mapping of dev autoloading rules
     * @phpstan-return array{psr-0?: array<string, string|string[]>, psr-4?: array<string, string|string[]>, classmap?: list<string>, files?: list<string>}
     */
    public function getDevAutoload();

    /**
     * Returns a list of directories which should get added to PHP's
     * include path.
     *
     * @return string[]
     */
    public function getIncludePaths();

    /**
     * Stores a reference to the repository that owns the package
     *
     * @param RepositoryInterface $repository
     *
     * @return void
     */
    public function setRepository(RepositoryInterface $repository);

    /**
     * Returns a reference to the repository that owns the package
     *
     * @return ?RepositoryInterface
     */
    public function getRepository();

    /**
     * Returns the package binaries
     *
     * @return string[]
     */
    public function getBinaries();

    /**
     * Returns package unique name, constructed from name and version.
     *
     * @return string
     */
    public function getUniqueName();

    /**
     * Returns the package notification url
     *
     * @return ?string
     */
    public function getNotificationUrl();

    /**
     * Converts the package into a readable and unique string
     *
     * @return string
     */
    public function __toString();

    /**
     * Converts the package into a pretty readable string
     *
     * @return string
     */
    public function getPrettyString();

    /**
     * @return bool
     */
    public function isDefaultBranch();

    /**
     * Returns a list of options to download package dist files
     *
     * @return mixed[]
     */
    public function getTransportOptions();

    /**
     * Configures the list of options to download package dist files
     *
     * @param mixed[] $options
     *
     * @return void
     */
    public function setTransportOptions(array $options);

    /**
     * @param string $reference
     *
     * @return void
     */
    public function setSourceReference($reference);

    /**
     * @param string $url
     *
     * @return void
     */
    public function setDistUrl($url);

    /**
     * @param string $type
     *
     * @return void
     */
    public function setDistType($type);

    /**
     * @param string $reference
     *
     * @return void
     */
    public function setDistReference($reference);

    /**
     * Set dist and source references and update dist URL for ones that contain a reference
     *
     * @param string $reference
     *
     * @return void
     */
    public function setSourceDistReferences($reference);
}
