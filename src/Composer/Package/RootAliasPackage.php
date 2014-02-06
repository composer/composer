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
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class RootAliasPackage extends AliasPackage implements RootPackageInterface
{
    /**
     * @var RootPackageInterface
     */
    protected $aliasOf;

    public function __construct(RootPackageInterface $aliasOf, $version, $prettyVersion)
    {
        parent::__construct($aliasOf, $version, $prettyVersion);
    }

    /**
     * {@inheritDoc}
     */
    public function getAliases()
    {
        return $this->aliasOf->getAliases();
    }

    /**
     * {@inheritDoc}
     */
    public function getMinimumStability()
    {
        return $this->aliasOf->getMinimumStability();
    }

    /**
     * {@inheritDoc}
     */
    public function getStabilityFlags()
    {
        return $this->aliasOf->getStabilityFlags();
    }

    /**
     * {@inheritDoc}
     */
    public function getReferences()
    {
        return $this->aliasOf->getReferences();
    }

    /**
     * {@inheritDoc}
     */
    public function getPreferStable()
    {
        return $this->aliasOf->getPreferStable();
    }

    /**
     * {@inheritDoc}
     */
    public function setRequires(array $require)
    {
        return $this->aliasOf->setRequires($require);
    }

    /**
     * {@inheritDoc}
     */
    public function setDevRequires(array $devRequire)
    {
        return $this->aliasOf->setDevRequires($devRequire);
    }

    public function __clone()
    {
        parent::__clone();
        $this->aliasOf = clone $this->aliasOf;
    }

    /**
     * {@inheritDoc}
     */
    public function setReferences(array $references)
    {
        $this->aliasOf->setReferences($references);
    }

    /**
     * {@inheritDoc}
     */
    public function setAliases(array $aliases)
    {
        $this->aliasOf->setAliases($aliases);
    }

    /**
     * {@inheritDoc}
     */
    public function setRepositories($repositories)
    {
        $this->aliasOf->setRepositories($repositories);
    }

    /**
     * {@inheritdoc}
     */
    public function setMinimumStability($minimumStability)
    {
        $this->aliasOf->setMinimumStability($minimumStability);
    }

    /**
     * {@inheritDoc}
     */
    public function setStabilityFlags(array $stabilityFlags)
    {
        $this->aliasOf->setStabilityFlags($stabilityFlags);
    }

    /**
     * {@inheritDoc}
     */
    public function setPreferStable($preferStable)
    {
        $this->aliasOf->setPreferStable($preferStable);
    }
}
