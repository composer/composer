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
 * The root package represents the project's composer.json and contains additional metadata
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class RootPackage extends CompletePackage implements RootPackageInterface
{
    const DEFAULT_PRETTY_VERSION = '1.0.0+no-version-set';

    protected $minimumStability = 'stable';
    protected $preferStable = false;
    protected $stabilityFlags = array();
    protected $config = array();
    protected $references = array();
    protected $aliases = array();

    /**
     * {@inerhitDoc}
     */
    public function setMinimumStability($minimumStability)
    {
        $this->minimumStability = $minimumStability;
    }

    /**
     * {@inheritDoc}
     */
    public function getMinimumStability()
    {
        return $this->minimumStability;
    }

    /**
     * {@inheritDoc}
     */
    public function setStabilityFlags(array $stabilityFlags)
    {
        $this->stabilityFlags = $stabilityFlags;
    }

    /**
     * {@inheritDoc}
     */
    public function getStabilityFlags()
    {
        return $this->stabilityFlags;
    }

    /**
     * {@inerhitDoc}
     */
    public function setPreferStable($preferStable)
    {
        $this->preferStable = $preferStable;
    }

    /**
     * {@inheritDoc}
     */
    public function getPreferStable()
    {
        return $this->preferStable;
    }

    /**
     * {@inerhitDoc}
     */
    public function setConfig(array $config)
    {
        $this->config = $config;
    }

    /**
     * {@inheritDoc}
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * {@inerhitDoc}
     */
    public function setReferences(array $references)
    {
        $this->references = $references;
    }

    /**
     * {@inheritDoc}
     */
    public function getReferences()
    {
        return $this->references;
    }

    /**
     * {@inerhitDoc}
     */
    public function setAliases(array $aliases)
    {
        $this->aliases = $aliases;
    }

    /**
     * {@inheritDoc}
     */
    public function getAliases()
    {
        return $this->aliases;
    }
}
