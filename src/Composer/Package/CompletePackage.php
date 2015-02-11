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
 * Package containing additional metadata that is not used by the solver
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class CompletePackage extends Package implements CompletePackageInterface
{
    protected $repositories;
    protected $license = array();
    protected $keywords;
    protected $authors;
    protected $description;
    protected $homepage;
    protected $scripts = array();
    protected $support = array();
    protected $abandoned = false;

    /**
     * @param array $scripts
     */
    public function setScripts(array $scripts)
    {
        $this->scripts = $scripts;
    }

    /**
     * {@inheritDoc}
     */
    public function getScripts()
    {
        return $this->scripts;
    }

    /**
     * Set the repositories
     *
     * @param array $repositories
     */
    public function setRepositories($repositories)
    {
        $this->repositories = $repositories;
    }

    /**
     * {@inheritDoc}
     */
    public function getRepositories()
    {
        return $this->repositories;
    }

    /**
     * Set the license
     *
     * @param array $license
     */
    public function setLicense(array $license)
    {
        $this->license = $license;
    }

    /**
     * {@inheritDoc}
     */
    public function getLicense()
    {
        return $this->license;
    }

    /**
     * Set the keywords
     *
     * @param array $keywords
     */
    public function setKeywords(array $keywords)
    {
        $this->keywords = $keywords;
    }

    /**
     * {@inheritDoc}
     */
    public function getKeywords()
    {
        return $this->keywords;
    }

    /**
     * Set the authors
     *
     * @param array $authors
     */
    public function setAuthors(array $authors)
    {
        $this->authors = $authors;
    }

    /**
     * {@inheritDoc}
     */
    public function getAuthors()
    {
        return $this->authors;
    }

    /**
     * Set the description
     *
     * @param string $description
     */
    public function setDescription($description)
    {
        $this->description = $description;
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Set the homepage
     *
     * @param string $homepage
     */
    public function setHomepage($homepage)
    {
        $this->homepage = $homepage;
    }

    /**
     * {@inheritDoc}
     */
    public function getHomepage()
    {
        return $this->homepage;
    }

    /**
     * Set the support information
     *
     * @param array $support
     */
    public function setSupport(array $support)
    {
        $this->support = $support;
    }

    /**
     * {@inheritDoc}
     */
    public function getSupport()
    {
        return $this->support;
    }

    /**
     * @return boolean
     */
    public function isAbandoned()
    {
        return (boolean) $this->abandoned;
    }

    /**
     * @param boolean|string $abandoned
     */
    public function setAbandoned($abandoned)
    {
        $this->abandoned = $abandoned;
    }

    /**
     * If the package is abandoned and has a suggested replacement, this method returns it
     *
     * @return string|null
     */
    public function getReplacementPackage()
    {
        return is_string($this->abandoned) ? $this->abandoned : null;
    }
}
