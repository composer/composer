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
 * A package with setters for all members to create it dynamically in memory
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class MemoryPackage extends BasePackage
{
    protected $type;
    protected $repositoryType;
    protected $repositoryUrl;
    protected $releaseType;
    protected $version;
    protected $license;

    protected $requires = array();
    protected $conflicts = array();
    protected $provides = array();
    protected $replaces = array();
    protected $recommends = array();
    protected $suggests = array();

    /**
     * Creates a new in memory package.
     *
     * @param string $name        The package's name
     * @param string $version     The package's version
     * @param string $releaseType The package's release type (beta/rc/stable/dev)
     */
    public function __construct($name, $version, $releaseType = 'stable')
    {
        parent::__construct($name);

        $this->releaseType = $releaseType;
        $this->version = $version;
    }

    /**
     * @param string $type
     */
    public function setType($type)
    {
        $this->type = $type;
    }

    /**
     * {@inheritDoc}
     */
    public function getType()
    {
        return $this->type ?: 'library';
    }

    /**
     * @param string $type
     */
    public function setRepositoryType($type)
    {
        $this->repositoryType = $type;
    }

    /**
     * {@inheritDoc}
     */
    public function getRepositoryType()
    {
        return $this->repositoryType;
    }

    /**
     * @param string $url
     */
    public function setRepositoryUrl($url)
    {
        $this->repositoryUrl = $url;
    }

    /**
     * {@inheritDoc}
     */
    public function getRepositoryUrl()
    {
        return $this->repositoryUrl;
    }

    /**
     * Set the release type
     *
     * @param string $releaseType
     */
    public function setReleaseType($releaseType)
    {
        $this->releaseType = $releaseType;
    }

    /**
     * {@inheritDoc}
     */
    public function getReleaseType()
    {
        return $this->releaseType;
    }

    /**
     * Set the version
     *
     * @param string $version
     */
    public function setVersion($version)
    {
        $this->version = $version;
    }

    /**
     * {@inheritDoc}
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * Set the license
     *
     * @param string $license
     */
    public function setLicense($license)
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
     * Set the required packages
     *
     * @param array $requires A set of package relations
     */
    public function setRequires(array $requires)
    {
        $this->requires = $requires;
    }

    /**
     * {@inheritDoc}
     */
    public function getRequires()
    {
        return $this->requires;
    }

    /**
     * Set the conflicting packages
     *
     * @param array $conflicts A set of package relations
     */
    public function setConflicts(array $conflicts)
    {
        $this->conflicts = $conflicts;
    }

    /**
     * {@inheritDoc}
     */
    public function getConflicts()
    {
        return $this->conflicts;
    }

    /**
     * Set the provided virtual packages
     *
     * @param array $conflicts A set of package relations
     */
    public function setProvides(array $provides)
    {
        $this->provides = $provides;
    }

    /**
     * {@inheritDoc}
     */
    public function getProvides()
    {
        return $this->provides;
    }

    /**
     * Set the packages this one replaces
     *
     * @param array $conflicts A set of package relations
     */
    public function setReplaces(array $replaces)
    {
        $this->replaces = $replaces;
    }

    /**
     * {@inheritDoc}
     */
    public function getReplaces()
    {
        return $this->replaces;
    }

    /**
     * Set the recommended packages
     *
     * @param array $conflicts A set of package relations
     */
    public function setRecommends(array $recommends)
    {
        $this->recommends = $recommends;
    }

    /**
     * {@inheritDoc}
     */
    public function getRecommends()
    {
        return $this->recommends;
    }

    /**
     * Set the suggested packages
     *
     * @param array $conflicts A set of package relations
     */
    public function setSuggests(array $suggests)
    {
        $this->suggests = $suggests;
    }

    /**
     * {@inheritDoc}
     */
    public function getSuggests()
    {
        return $this->suggests;
    }
}
