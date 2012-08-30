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

namespace Composer\Test\Mock;

use Composer\Package\PackageInterface;

/**
 * Mock class for PackageInterface.
 * 
 * More fields might be added if required in test cases.
 */
class PackageMock implements PackageInterface
{

    private $isDev;
    private $prettyVersion;
    private $sourceReference;
    private $sourceType;

    public function __toString()
    {
        return 'PackageMock';
    }

    public function getAlias()
    {
        
    }

    public function getAutoload()
    {
        
    }

    public function getBinaries()
    {
        
    }

    public function getConflicts()
    {
        
    }

    public function getDevRequires()
    {
        
    }

    public function getDistReference()
    {
        
    }

    public function getDistSha1Checksum()
    {
        
    }

    public function getDistType()
    {
        
    }

    public function getDistUrl()
    {
        
    }

    public function getExtra()
    {
        
    }

    public function getId()
    {
        
    }

    public function getIncludePaths()
    {
        
    }

    public function getInstallationSource()
    {
        
    }

    public function getName()
    {
        
    }

    public function getNames()
    {
        
    }

    public function getPrettyAlias()
    {
        
    }

    public function getPrettyName()
    {
        
    }

    public function getPrettyString()
    {
        
    }

    public function getPrettyVersion()
    {
        return $this->prettyVersion;
    }

    public function getProvides()
    {
        
    }

    public function getReleaseDate()
    {
        
    }

    public function getReplaces()
    {
        
    }

    public function getRepository()
    {
        
    }

    public function getRequires()
    {
        
    }

    public function getSourceReference()
    {
        return $this->sourceReference;
    }

    public function getSourceType()
    {
        return $this->sourceType;
    }

    public function getSourceUrl()
    {
        
    }

    public function getStability()
    {
        
    }

    public function getSuggests()
    {
        
    }

    public function getTargetDir()
    {
        
    }

    public function getType()
    {
        
    }

    public function getUniqueName()
    {
        
    }

    public function getVersion()
    {
        
    }

    public function isDev()
    {
        return $this->isDev;
    }

    public function setId($id)
    {
        
    }

    public function setInstallationSource($type)
    {
        
    }

    public function setIsDev($isDev)
    {
        $this->isDev = $isDev;
    }

    public function setPrettyVersion($prettyVersion)
    {
        $this->prettyVersion = $prettyVersion;
    }

    public function setSourceReference($sourceReference)
    {
        $this->sourceReference = $sourceReference;
    }

    public function setSourceType($sourceType)
    {
        $this->sourceType = $sourceType;
    }

    public function setRepository(\Composer\Repository\RepositoryInterface $repository)
    {
        
    }
}
