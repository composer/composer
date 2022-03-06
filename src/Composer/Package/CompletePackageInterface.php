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

/**
 * Defines package metadata that is not necessarily needed for solving and installing packages
 *
 * PackageInterface & derivatives are considered internal, you may use them in type hints but extending/implementing them is not recommended and not supported. Things may change without notice.
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
interface CompletePackageInterface extends PackageInterface
{
    /**
     * Returns the scripts of this package
     *
     * @return array<string, string[]> Map of script name to array of handlers
     */
    public function getScripts(): array;

    /**
     * @param  array<string, string[]> $scripts
     * @return void
     */
    public function setScripts(array $scripts): void;

    /**
     * Returns an array of repositories
     *
     * @return mixed[] Repositories
     */
    public function getRepositories(): array;

    /**
     * Set the repositories
     *
     * @param  mixed[] $repositories
     * @return void
     */
    public function setRepositories(array $repositories): void;

    /**
     * Returns the package license, e.g. MIT, BSD, GPL
     *
     * @return string[] The package licenses
     */
    public function getLicense(): array;

    /**
     * Set the license
     *
     * @param  string[] $license
     * @return void
     */
    public function setLicense(array $license): void;

    /**
     * Returns an array of keywords relating to the package
     *
     * @return string[]
     */
    public function getKeywords(): array;

    /**
     * Set the keywords
     *
     * @param  string[] $keywords
     * @return void
     */
    public function setKeywords(array $keywords): void;

    /**
     * Returns the package description
     *
     * @return ?string
     */
    public function getDescription(): ?string;

    /**
     * Set the description
     *
     * @param  string $description
     * @return void
     */
    public function setDescription(string $description): void;

    /**
     * Returns the package homepage
     *
     * @return ?string
     */
    public function getHomepage(): ?string;

    /**
     * Set the homepage
     *
     * @param  string $homepage
     * @return void
     */
    public function setHomepage(string $homepage): void;

    /**
     * Returns an array of authors of the package
     *
     * Each item can contain name/homepage/email keys
     *
     * @return array<array{name?: string, homepage?: string, email?: string, role?: string}>
     */
    public function getAuthors(): array;

    /**
     * Set the authors
     *
     * @param  array<array{name?: string, homepage?: string, email?: string, role?: string}> $authors
     * @return void
     */
    public function setAuthors(array $authors): void;

    /**
     * Returns the support information
     *
     * @return array{issues?: string, forum?: string, wiki?: string, source?: string, email?: string, irc?: string, docs?: string, rss?: string, chat?: string}
     */
    public function getSupport(): array;

    /**
     * Set the support information
     *
     * @param  array{issues?: string, forum?: string, wiki?: string, source?: string, email?: string, irc?: string, docs?: string, rss?: string, chat?: string} $support
     * @return void
     */
    public function setSupport(array $support): void;

    /**
     * Returns an array of funding options for the package
     *
     * Each item will contain type and url keys
     *
     * @return array<array{type?: string, url?: string}>
     */
    public function getFunding(): array;

    /**
     * Set the funding
     *
     * @param  array<array{type?: string, url?: string}> $funding
     * @return void
     */
    public function setFunding(array $funding): void;

    /**
     * Returns if the package is abandoned or not
     *
     * @return bool
     */
    public function isAbandoned(): bool;

    /**
     * If the package is abandoned and has a suggested replacement, this method returns it
     *
     * @return string|null
     */
    public function getReplacementPackage(): ?string;

    /**
     * @param  bool|string $abandoned
     * @return void
     */
    public function setAbandoned($abandoned): void;

    /**
     * Returns default base filename for archive
     *
     * @return ?string
     */
    public function getArchiveName(): ?string;

    /**
     * Sets default base filename for archive
     *
     * @param  string $name
     * @return void
     */
    public function setArchiveName(string $name): void;

    /**
     * Returns a list of patterns to exclude from package archives
     *
     * @return string[]
     */
    public function getArchiveExcludes(): array;

    /**
     * Sets a list of patterns to be excluded from archives
     *
     * @param  string[] $excludes
     * @return void
     */
    public function setArchiveExcludes(array $excludes): void;
}
