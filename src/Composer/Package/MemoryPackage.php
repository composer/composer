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
    protected $installationSource;
    protected $sourceType;
    protected $sourceUrl;
    protected $sourceReference;
    protected $distType;
    protected $distUrl;
    protected $distReference;
    protected $distSha1Checksum;
    protected $releaseType;
    protected $version;
    protected $license;
    protected $extra = array();

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
     * Create memory copy of package
     *
     * @param PackageInterface $package Package to copy
     *
     * @return MemoryPackage
     */
    public static function fromPackage(PackageInterface $package)
    {
        // TODO: Fix releaseType. There is no releaseType in the PackageInterface
        $newPackage = new self($package->getPrettyName(), $package->getVersion());
        $newPackage->setType($package->getType());
        $newPackage->setExtra($package->getExtra());

        $newPackage->setSourceType($package->getSourceType());
        $newPackage->setSourceUrl($package->getSourceUrl());
        $newPackage->setSourceReference($package->getSourceReference());

        $newPackage->setDistType($package->getDistType());
        $newPackage->setDistUrl($package->getDistUrl());
        $newPackage->setDistReference($package->getDistReference());
        $newPackage->setDistSha1Checksum($package->getDistSha1Checksum());

        $newPackage->setLicense($package->getLicense());

        $newPackage->setRequires($package->getRequires());
        $newPackage->setConflicts($package->getConflicts());
        $newPackage->setProvides($package->getProvides());
        $newPackage->setReplaces($package->getReplaces());
        $newPackage->setRecommends($package->getRecommends());
        $newPackage->setSuggests($package->getSuggests());

        return $newPackage;
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
     * @param array $extra
     */
    public function setExtra(array $extra)
    {
        $this->extra = $extra;
    }

    /**
     * {@inheritDoc}
     */
    public function getExtra()
    {
        return $this->extra;
    }

    /**
     * {@inheritDoc}
     */
    public function setInstallationSource($type)
    {
        $this->installationSource = $type;
    }

    /**
     * {@inheritDoc}
     */
    public function getInstallationSource()
    {
        return $this->installationSource;
    }

    /**
     * @param string $type
     */
    public function setSourceType($type)
    {
        $this->sourceType = $type;
    }

    /**
     * {@inheritDoc}
     */
    public function getSourceType()
    {
        return $this->sourceType;
    }

    /**
     * @param string $url
     */
    public function setSourceUrl($url)
    {
        $this->sourceUrl = $url;
    }

    /**
     * {@inheritDoc}
     */
    public function getSourceUrl()
    {
        return $this->sourceUrl;
    }

    /**
     * @param string $reference
     */
    public function setSourceReference($reference)
    {
        $this->sourceReference = $reference;
    }

    /**
     * {@inheritDoc}
     */
    public function getSourceReference()
    {
        return $this->sourceReference;
    }

    /**
     * @param string $type
     */
    public function setDistType($type)
    {
        $this->distType = $type;
    }

    /**
     * {@inheritDoc}
     */
    public function getDistType()
    {
        return $this->distType;
    }

    /**
     * @param string $url
     */
    public function setDistUrl($url)
    {
        $this->distUrl = $url;
    }

    /**
     * {@inheritDoc}
     */
    public function getDistUrl()
    {
        return $this->distUrl;
    }

    /**
     * @param string $reference
     */
    public function setDistReference($reference)
    {
        $this->distReference = $reference;
    }

    /**
     * {@inheritDoc}
     */
    public function getDistReference()
    {
        return $this->distReference;
    }

    /**
     * @param string $sha1checksum
     */
    public function setDistSha1Checksum($sha1checksum)
    {
        $this->distSha1Checksum = $sha1checksum;
    }

    /**
     * {@inheritDoc}
     */
    public function getDistSha1Checksum()
    {
        return $this->distSha1Checksum;
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
     * @param array $requires A set of package links
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
     * @param array $conflicts A set of package links
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
     * @param array $provides A set of package links
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
     * @param array $replaces A set of package links
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
     * @param array $recommends A set of package links
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
     * @param array $suggests A set of package links
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
