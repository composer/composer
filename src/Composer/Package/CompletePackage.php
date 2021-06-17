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
    protected $repositories = array();
    protected $license = array();
    protected $keywords = array();
    protected $authors = array();
    protected $description;
    protected $homepage;
    protected $scripts = array();
    protected $support = array();
    protected $funding = array();
    protected $abandoned = false;
    protected $archiveName;
    protected $archiveExcludes = array();

    /**
     * {@inheritDoc}
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
     * {@inheritDoc}
     */
    public function setRepositories(array $repositories)
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
     * {@inheritDoc}
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
     * {@inheritDoc}
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
     * {@inheritDoc}
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
     * {@inheritDoc}
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
     * {@inheritDoc}
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
     * {@inheritDoc}
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
     * {@inheritDoc}
     */
    public function setFunding(array $funding)
    {
        $this->funding = $funding;
    }

    /**
     * {@inheritDoc}
     */
    public function getFunding()
    {
        return $this->funding;
    }

    /**
     * {@inheritDoc}
     */
    public function isAbandoned()
    {
        return (bool) $this->abandoned;
    }

    /**
     * {@inheritDoc}
     */
    public function setAbandoned($abandoned)
    {
        $this->abandoned = $abandoned;
    }

    /**
     * {@inheritDoc}
     */
    public function getReplacementPackage()
    {
        return \is_string($this->abandoned) ? $this->abandoned : null;
    }

    /**
     * {@inheritDoc}
     */
    public function setArchiveName($name)
    {
        $this->archiveName = $name;
    }

    /**
     * {@inheritDoc}
     */
    public function getArchiveName()
    {
        return $this->archiveName;
    }

    /**
     * {@inheritDoc}
     */
    public function setArchiveExcludes(array $excludes)
    {
        $this->archiveExcludes = $excludes;
    }

    /**
     * {@inheritDoc}
     */
    public function getArchiveExcludes()
    {
        return $this->archiveExcludes;
    }
}
