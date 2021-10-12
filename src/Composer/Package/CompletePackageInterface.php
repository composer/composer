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
 * Defines package metadata that is not necessarily needed for solving and installing packages
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
    public function getScripts();

    /**
     * @param  array<string, string[]> $scripts
     * @return void
     */
    public function setScripts(array $scripts);

    /**
     * Returns an array of repositories
     *
     * @return mixed[] Repositories
     */
    public function getRepositories();

    /**
     * Set the repositories
     *
     * @param  mixed[] $repositories
     * @return void
     */
    public function setRepositories(array $repositories);

    /**
     * Returns the package license, e.g. MIT, BSD, GPL
     *
     * @return string[] The package licenses
     */
    public function getLicense();

    /**
     * Set the license
     *
     * @param  string[] $license
     * @return void
     */
    public function setLicense(array $license);

    /**
     * Returns an array of keywords relating to the package
     *
     * @return string[]
     */
    public function getKeywords();

    /**
     * Set the keywords
     *
     * @param  string[] $keywords
     * @return void
     */
    public function setKeywords(array $keywords);

    /**
     * Returns the package description
     *
     * @return ?string
     */
    public function getDescription();

    /**
     * Set the description
     *
     * @param  string $description
     * @return void
     */
    public function setDescription($description);

    /**
     * Returns the package homepage
     *
     * @return ?string
     */
    public function getHomepage();

    /**
     * Set the homepage
     *
     * @param  string $homepage
     * @return void
     */
    public function setHomepage($homepage);

    /**
     * Returns an array of authors of the package
     *
     * Each item can contain name/homepage/email keys
     *
     * @return array<array{name?: string, homepage?: string, email?: string, role?: string}>
     */
    public function getAuthors();

    /**
     * Set the authors
     *
     * @param  array<array{name?: string, homepage?: string, email?: string, role?: string}> $authors
     * @return void
     */
    public function setAuthors(array $authors);

    /**
     * Returns the support information
     *
     * @return array{issues?: string, forum?: string, wiki?: string, source?: string, email?: string, irc?: string, docs?: string, rss?: string, chat?: string}
     */
    public function getSupport();

    /**
     * Set the support information
     *
     * @param  array{issues?: string, forum?: string, wiki?: string, source?: string, email?: string, irc?: string, docs?: string, rss?: string, chat?: string} $support
     * @return void
     */
    public function setSupport(array $support);

    /**
     * Returns an array of funding options for the package
     *
     * Each item will contain type and url keys
     *
     * @return array<array{type?: string, url?: string}>
     */
    public function getFunding();

    /**
     * Set the funding
     *
     * @param  array<array{type?: string, url?: string}> $funding
     * @return void
     */
    public function setFunding(array $funding);

    /**
     * Returns if the package is abandoned or not
     *
     * @return bool
     */
    public function isAbandoned();

    /**
     * If the package is abandoned and has a suggested replacement, this method returns it
     *
     * @return string|null
     */
    public function getReplacementPackage();

    /**
     * @param  bool|string $abandoned
     * @return void
     */
    public function setAbandoned($abandoned);

    /**
     * Returns default base filename for archive
     *
     * @return ?string
     */
    public function getArchiveName();

    /**
     * Sets default base filename for archive
     *
     * @param  string $name
     * @return void
     */
    public function setArchiveName($name);

    /**
     * Returns a list of patterns to exclude from package archives
     *
     * @return string[]
     */
    public function getArchiveExcludes();

    /**
     * Sets a list of patterns to be excluded from archives
     *
     * @param  string[] $excludes
     * @return void
     */
    public function setArchiveExcludes(array $excludes);
}
