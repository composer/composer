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
class CompleteAliasPackage extends AliasPackage implements CompletePackageInterface
{
    /** @var CompletePackage */
    protected $aliasOf;

    /**
     * All descendants' constructors should call this parent constructor
     *
     * @param CompletePackage $aliasOf       The package this package is an alias of
     * @param string          $version       The version the alias must report
     * @param string          $prettyVersion The alias's non-normalized version
     */
    public function __construct(CompletePackage $aliasOf, $version, $prettyVersion)
    {
        parent::__construct($aliasOf, $version, $prettyVersion);
    }

    /**
     * @return CompletePackage
     */
    public function getAliasOf()
    {
        return $this->aliasOf;
    }

    public function getScripts()
    {
        return $this->aliasOf->getScripts();
    }

    public function setScripts(array $scripts)
    {
        $this->aliasOf->setScripts($scripts);
    }

    public function getRepositories()
    {
        return $this->aliasOf->getRepositories();
    }

    public function setRepositories(array $repositories)
    {
        $this->aliasOf->setRepositories($repositories);
    }

    public function getLicense()
    {
        return $this->aliasOf->getLicense();
    }

    public function setLicense(array $license)
    {
        $this->aliasOf->setLicense($license);
    }

    public function getKeywords()
    {
        return $this->aliasOf->getKeywords();
    }

    public function setKeywords(array $keywords)
    {
        $this->aliasOf->setKeywords($keywords);
    }

    public function getDescription()
    {
        return $this->aliasOf->getDescription();
    }

    public function setDescription($description)
    {
        $this->aliasOf->setDescription($description);
    }

    public function getHomepage()
    {
        return $this->aliasOf->getHomepage();
    }

    public function setHomepage($homepage)
    {
        $this->aliasOf->setHomepage($homepage);
    }

    public function getAuthors()
    {
        return $this->aliasOf->getAuthors();
    }

    public function setAuthors(array $authors)
    {
        $this->aliasOf->setAuthors($authors);
    }

    public function getSupport()
    {
        return $this->aliasOf->getSupport();
    }

    public function setSupport(array $support)
    {
        $this->aliasOf->setSupport($support);
    }

    public function getFunding()
    {
        return $this->aliasOf->getFunding();
    }

    public function setFunding(array $funding)
    {
        $this->aliasOf->setFunding($funding);
    }

    public function isAbandoned()
    {
        return $this->aliasOf->isAbandoned();
    }

    public function getReplacementPackage()
    {
        return $this->aliasOf->getReplacementPackage();
    }

    public function setAbandoned($abandoned)
    {
        $this->aliasOf->setAbandoned($abandoned);
    }

    public function getArchiveName()
    {
        return $this->aliasOf->getArchiveName();
    }

    public function setArchiveName($name)
    {
        $this->aliasOf->setArchiveName($name);
    }

    public function getArchiveExcludes()
    {
        return $this->aliasOf->getArchiveExcludes();
    }

    public function setArchiveExcludes(array $excludes)
    {
        $this->aliasOf->setArchiveExcludes($excludes);
    }
}
