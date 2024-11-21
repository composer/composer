<?php declare(strict_types=1);

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
 * PackageInterface & derivatives are considered internal, you may use them in type hints but extending/implementing them is not recommended and not supported. Things may change without notice.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 *
 * @phpstan-type AutoloadRules    array{psr-0?: array<string, string|string[]>, psr-4?: array<string, string|string[]>, classmap?: list<string>, files?: list<string>, exclude-from-classmap?: list<string>}
 * @phpstan-type DevAutoloadRules array{psr-0?: array<string, string|string[]>, psr-4?: array<string, string|string[]>, classmap?: list<string>, files?: list<string>}
 * @phpstan-type PhpExtConfig     array{extension-name?: string, priority?: int, support-zts?: bool, support-nts?: bool, build-path?: string|null, configure-options?: list<array{name: string, description?: string}>}
 */
interface PackageInterface
{
    public const DISPLAY_SOURCE_REF_IF_DEV = 0;
    public const DISPLAY_SOURCE_REF = 1;
    public const DISPLAY_DIST_REF = 2;

    /**
     * Returns the package's name without version info, thus not a unique identifier
     *
     * @return string package name
     */
    public function getName(): string;

    /**
     * Returns the package's pretty (i.e. with proper case) name
     *
     * @return string package name
     */
    public function getPrettyName(): string;

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
    public function getNames(bool $provides = true): array;

    /**
     * Allows the solver to set an id for this package to refer to it.
     */
    public function setId(int $id): void;

    /**
     * Retrieves the package's id set through setId
     *
     * @return int The previously set package id
     */
    public function getId(): int;

    /**
     * Returns whether the package is a development virtual package or a concrete one
     */
    public function isDev(): bool;

    /**
     * Returns the package type, e.g. library
     *
     * @return string The package type
     */
    public function getType(): string;

    /**
     * Returns the package targetDir property
     *
     * @return ?string The package targetDir
     */
    public function getTargetDir(): ?string;

    /**
     * Returns the package extra data
     *
     * @return mixed[] The package extra data
     */
    public function getExtra(): array;

    /**
     * Sets source from which this package was installed (source/dist).
     *
     * @param ?string $type source/dist
     * @phpstan-param 'source'|'dist'|null $type
     */
    public function setInstallationSource(?string $type): void;

    /**
     * Returns source from which this package was installed (source/dist).
     *
     * @return ?string source/dist
     * @phpstan-return 'source'|'dist'|null
     */
    public function getInstallationSource(): ?string;

    /**
     * Returns the repository type of this package, e.g. git, svn
     *
     * @return ?string The repository type
     */
    public function getSourceType(): ?string;

    /**
     * Returns the repository url of this package, e.g. git://github.com/naderman/composer.git
     *
     * @return ?string The repository url
     */
    public function getSourceUrl(): ?string;

    /**
     * Returns the repository urls of this package including mirrors, e.g. git://github.com/naderman/composer.git
     *
     * @return list<string>
     */
    public function getSourceUrls(): array;

    /**
     * Returns the repository reference of this package, e.g. master, 1.0.0 or a commit hash for git
     *
     * @return ?string The repository reference
     */
    public function getSourceReference(): ?string;

    /**
     * Returns the source mirrors of this package
     *
     * @return ?list<array{url: non-empty-string, preferred: bool}>
     */
    public function getSourceMirrors(): ?array;

    /**
     * @param  null|list<array{url: non-empty-string, preferred: bool}> $mirrors
     */
    public function setSourceMirrors(?array $mirrors): void;

    /**
     * Returns the type of the distribution archive of this version, e.g. zip, tarball
     *
     * @return ?string The repository type
     */
    public function getDistType(): ?string;

    /**
     * Returns the url of the distribution archive of this version
     *
     * @return ?non-empty-string
     */
    public function getDistUrl(): ?string;

    /**
     * Returns the urls of the distribution archive of this version, including mirrors
     *
     * @return non-empty-string[]
     */
    public function getDistUrls(): array;

    /**
     * Returns the reference of the distribution archive of this version, e.g. master, 1.0.0 or a commit hash for git
     *
     * @return ?string
     */
    public function getDistReference(): ?string;

    /**
     * Returns the sha1 checksum for the distribution archive of this version
     *
     * Can be an empty string which should be treated as null
     *
     * @return ?string
     */
    public function getDistSha1Checksum(): ?string;

    /**
     * Returns the dist mirrors of this package
     *
     * @return ?list<array{url: non-empty-string, preferred: bool}>
     */
    public function getDistMirrors(): ?array;

    /**
     * @param  null|list<array{url: non-empty-string, preferred: bool}> $mirrors
     */
    public function setDistMirrors(?array $mirrors): void;

    /**
     * Returns the version of this package
     *
     * @return string version
     */
    public function getVersion(): string;

    /**
     * Returns the pretty (i.e. non-normalized) version string of this package
     *
     * @return string version
     */
    public function getPrettyVersion(): string;

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
    public function getFullPrettyVersion(bool $truncate = true, int $displayMode = self::DISPLAY_SOURCE_REF_IF_DEV): string;

    /**
     * Returns the release date of the package
     *
     * @return ?\DateTimeInterface
     */
    public function getReleaseDate(): ?\DateTimeInterface;

    /**
     * Returns the stability of this package: one of (dev, alpha, beta, RC, stable)
     *
     * @phpstan-return 'stable'|'RC'|'beta'|'alpha'|'dev'
     */
    public function getStability(): string;

    /**
     * Returns a set of links to packages which need to be installed before
     * this package can be installed
     *
     * @return array<string, Link> A map of package links defining required packages, indexed by the require package's name
     */
    public function getRequires(): array;

    /**
     * Returns a set of links to packages which must not be installed at the
     * same time as this package
     *
     * @return Link[] An array of package links defining conflicting packages
     */
    public function getConflicts(): array;

    /**
     * Returns a set of links to virtual packages that are provided through
     * this package
     *
     * @return Link[] An array of package links defining provided packages
     */
    public function getProvides(): array;

    /**
     * Returns a set of links to packages which can alternatively be
     * satisfied by installing this package
     *
     * @return Link[] An array of package links defining replaced packages
     */
    public function getReplaces(): array;

    /**
     * Returns a set of links to packages which are required to develop
     * this package. These are installed if in dev mode.
     *
     * @return array<string, Link> A map of package links defining packages required for development, indexed by the require package's name
     */
    public function getDevRequires(): array;

    /**
     * Returns a set of package names and reasons why they are useful in
     * combination with this package.
     *
     * @return array An array of package suggestions with descriptions
     * @phpstan-return array<string, string>
     */
    public function getSuggests(): array;

    /**
     * Returns an associative array of autoloading rules
     *
     * {"<type>": {"<namespace": "<directory>"}}
     *
     * Type is either "psr-4", "psr-0", "classmap" or "files". Namespaces are mapped to
     * directories for autoloading using the type specified.
     *
     * @return array Mapping of autoloading rules
     * @phpstan-return AutoloadRules
     */
    public function getAutoload(): array;

    /**
     * Returns an associative array of dev autoloading rules
     *
     * {"<type>": {"<namespace": "<directory>"}}
     *
     * Type is either "psr-4", "psr-0", "classmap" or "files". Namespaces are mapped to
     * directories for autoloading using the type specified.
     *
     * @return array Mapping of dev autoloading rules
     * @phpstan-return DevAutoloadRules
     */
    public function getDevAutoload(): array;

    /**
     * Returns a list of directories which should get added to PHP's
     * include path.
     *
     * @return string[]
     */
    public function getIncludePaths(): array;

    /**
     * Returns the settings for php extension packages
     *
     * @return array|null
     *
     * @phpstan-return PhpExtConfig|null
     */
    public function getPhpExt(): ?array;

    /**
     * Stores a reference to the repository that owns the package
     */
    public function setRepository(RepositoryInterface $repository): void;

    /**
     * Returns a reference to the repository that owns the package
     *
     * @return ?RepositoryInterface
     */
    public function getRepository(): ?RepositoryInterface;

    /**
     * Returns the package binaries
     *
     * @return string[]
     */
    public function getBinaries(): array;

    /**
     * Returns package unique name, constructed from name and version.
     */
    public function getUniqueName(): string;

    /**
     * Returns the package notification url
     *
     * @return ?string
     */
    public function getNotificationUrl(): ?string;

    /**
     * Converts the package into a readable and unique string
     */
    public function __toString(): string;

    /**
     * Converts the package into a pretty readable string
     */
    public function getPrettyString(): string;

    public function isDefaultBranch(): bool;

    /**
     * Returns a list of options to download package dist files
     *
     * @return mixed[]
     */
    public function getTransportOptions(): array;

    /**
     * Configures the list of options to download package dist files
     *
     * @param mixed[] $options
     */
    public function setTransportOptions(array $options): void;

    public function setSourceReference(?string $reference): void;

    public function setDistUrl(?string $url): void;

    public function setDistType(?string $type): void;

    public function setDistReference(?string $reference): void;

    /**
     * Set dist and source references and update dist URL for ones that contain a reference
     */
    public function setSourceDistReferences(string $reference): void;
}
