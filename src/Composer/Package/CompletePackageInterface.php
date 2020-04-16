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
     * @return array[] array('script name' => array('listeners'))
     * @psalm-return array<string, string[]>
     */
    public function getScripts();

    /**
     * Returns an array of repositories
     *
     * @return array[] Repositories
     * @psalm-return array<array{type: string, url?: string}>
     */
    public function getRepositories();

    /**
     * Returns the package license, e.g. MIT, BSD, GPL
     *
     * @return string[] The package licenses
     */
    public function getLicense();

    /**
     * Returns an array of keywords relating to the package
     *
     * @return string[]
     */
    public function getKeywords();

    /**
     * Returns the package description
     *
     * @return string
     */
    public function getDescription();

    /**
     * Returns the package homepage
     *
     * @return string
     */
    public function getHomepage();

    /**
     * Returns an array of authors of the package
     *
     * Each item can contain name/homepage/email keys
     *
     * @return array[]
     * @psalm-return array<array{?name: string, homepage?: string, email?: string, role?: string}>
     */
    public function getAuthors();

    /**
     * Returns the support information
     *
     * @return array
     * @psalm-return array<string, string>
     */
    public function getSupport();

    /**
     * Returns an array of funding options for the package
     *
     * Each item will contain type and url keys
     *
     * @return array[]
     * @psalm-return array<array{type: string, url: string}>
     */
    public function getFunding();

    /**
     * Returns if the package is abandoned or not
     *
     * @return bool
     */
    public function isAbandoned();

    /**
     * If the package is abandoned and has a suggested replacement, this method returns it
     *
     * @return string
     */
    public function getReplacementPackage();
}
