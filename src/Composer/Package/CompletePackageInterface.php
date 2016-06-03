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
     * @return array array('script name' => array('listeners'))
     */
    public function getScripts();

    /**
     * Returns an array of repositories
     *
     * {"<type>": {<config key/values>}}
     *
     * @return array Repositories
     */
    public function getRepositories();

    /**
     * Returns the package license, e.g. MIT, BSD, GPL
     *
     * @return array The package licenses
     */
    public function getLicense();

    /**
     * Returns an array of keywords relating to the package
     *
     * @return array
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
     * @return array
     */
    public function getAuthors();

    /**
     * Returns the support information
     *
     * @return array
     */
    public function getSupport();

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
