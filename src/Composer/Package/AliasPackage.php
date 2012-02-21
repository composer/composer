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

use Composer\Package\LinkConstraint\LinkConstraintInterface;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\PlatformRepository;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class AliasPackage extends BasePackage
{
    protected $version;
    protected $dev;
    protected $aliasOf;

    protected $requires;
    protected $conflicts;
    protected $provides;
    protected $replaces;
    protected $recommends;
    protected $suggests;

    /**
     * All descendants' constructors should call this parent constructor
     *
     * @param PackageInterface $aliasOf The package this package is an alias of
     * @param string $version The version the alias must report
     */
    public function __construct($aliasOf, $version)
    {
        parent::__construct($aliasOf->getName());

        $this->version = $version;
        $this->aliasOf = $aliasOf;
        $this->dev = 'dev-' === substr($version, 0, 4) || '-dev' === substr($version, -4);

        foreach (self::$supportedLinkTypes as $type => $description) {
            $links = $aliasOf->{'get'.ucfirst($description)}();
            $newLinks = array();
            foreach ($links as $link) {
                // link is self.version, but must be replacing also the replaced version
                if ('self.version' === $link->getPrettyConstraint()) {
                    $newLinks[] = new Link($link->getSource(), $link->getTarget(), new VersionConstraint('=', $this->version), $description, $this->version);
                }
            }
            $this->$description = array_merge($links, $newLinks);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * {@inheritDoc}
     */
    public function getPrettyVersion()
    {
        return $this->version;
    }

    /**
     * {@inheritDoc}
     */
    public function isDev()
    {
        return $this->dev;
    }

    /**
     * {@inheritDoc}
     */
    function getRequires()
    {
        return $this->requires;
    }

    /**
     * {@inheritDoc}
     */
    function getConflicts()
    {
        return $this->conflicts;
    }

    /**
     * {@inheritDoc}
     */
    function getProvides()
    {
        return $this->provides;
    }

    /**
     * {@inheritDoc}
     */
    function getReplaces()
    {
        return $this->replaces;
    }

    /**
     * {@inheritDoc}
     */
    function getRecommends()
    {
        return $this->recommends;
    }

    /**
     * {@inheritDoc}
     */
    function getSuggests()
    {
        return $this->suggests;
    }

    /***************************************
     * Wrappers around the aliased package *
     ***************************************/

    public function getType()
    {
        return $this->aliasOf->getType();
    }
    public function getTargetDir()
    {
        return $this->aliasOf->getTargetDir();
    }
    public function getExtra()
    {
        return $this->aliasOf->getExtra();
    }
    public function setInstallationSource($type)
    {
        $this->aliasOf->setInstallationSource($type);
    }
    public function getInstallationSource()
    {
        return $this->aliasOf->getInstallationSource();
    }
    public function getSourceType()
    {
        return $this->aliasOf->getSourceType();
    }
    public function getSourceUrl()
    {
        return $this->aliasOf->getSourceUrl();
    }
    public function getSourceReference()
    {
        return $this->aliasOf->getSourceReference();
    }
    public function getDistType()
    {
        return $this->aliasOf->getDistType();
    }
    public function getDistUrl()
    {
        return $this->aliasOf->getDistUrl();
    }
    public function getDistReference()
    {
        return $this->aliasOf->getDistReference();
    }
    public function getDistSha1Checksum()
    {
        return $this->aliasOf->getDistSha1Checksum();
    }
    public function getScripts()
    {
        return $this->aliasOf->getScripts();
    }
    public function getLicense()
    {
        return $this->aliasOf->getLicense();
    }
    public function getAutoload()
    {
        return $this->aliasOf->getAutoload();
    }
    public function getRepositories()
    {
        return $this->aliasOf->getRepositories();
    }
    public function getReleaseDate()
    {
        return $this->aliasOf->getReleaseDate();
    }
    public function getKeywords()
    {
        return $this->aliasOf->getKeywords();
    }
    public function getDescription()
    {
        return $this->aliasOf->getDescription();
    }
    public function getHomepage()
    {
        return $this->aliasOf->getHomepage();
    }
    public function getAuthors()
    {
        return $this->aliasOf->getAuthors();
    }
    public function __toString()
    {
        return $this->aliasOf->__toString();
    }
}
