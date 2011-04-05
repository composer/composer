<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\DependencyResolver;

/**
 * A package with setters for all members to create it dynamically in memory
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class MemoryPackage extends Package
{
    protected $releaseType;
    protected $version;

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
     * @param string $releaseType The package's release type (beta/rc/stable)
     * @param int    $id          A positive unique id, zero to auto generate
     */
    public function __construct($name, $version, $releaseType = 'stable', $id = 0)
    {
        parent::__construct($name, $id);

        $this->releaseType = $releaseType;
        $this->version = $version;
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
