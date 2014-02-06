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

use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Package\Version\VersionParser;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class AliasPackage extends CompletePackage implements CompletePackageInterface
{
    protected $version;
    protected $prettyVersion;
    protected $dev;

    /**
     * @var CompletePackageInterface
     */
    protected $aliasOf;
    protected $rootPackageAlias = false;
    protected $stability;

    protected $requires;
    protected $conflicts;
    protected $provides;
    protected $replaces;
    protected $recommends;
    protected $suggests;

    /**
     * All descendants' constructors should call this parent constructor
     *
     * @param \Composer\Package\PackageInterface $aliasOf The package this package is an alias of
     * @param string $version       The version the alias must report
     * @param string $prettyVersion The alias's non-normalized version
     */
    public function __construct(PackageInterface $aliasOf, $version, $prettyVersion)
    {
        parent::__construct($aliasOf->getName(), $version, $prettyVersion);

        $this->aliasOf = $aliasOf;

        // replace self.version dependencies
        foreach (array('requires', 'devRequires') as $type) {
            $links = $aliasOf->{'get'.ucfirst($type)}();
            foreach ($links as $index => $link) {
                /** @var Link $link */
                // link is self.version, but must be replacing also the replaced version
                if ('self.version' === $link->getPrettyConstraint()) {
                    $links[$index] = new Link($link->getSource(), $link->getTarget(), new VersionConstraint('=', $this->version), $type, $prettyVersion);
                }
            }
            $this->$type = $links;
        }

        // duplicate self.version provides
        foreach (array('conflicts', 'provides', 'replaces') as $type) {
            $links = $aliasOf->{'get'.ucfirst($type)}();
            $newLinks = array();
            foreach ($links as $link) {
                // link is self.version, but must be replacing also the replaced version
                if ('self.version' === $link->getPrettyConstraint()) {
                    $newLinks[] = new Link($link->getSource(), $link->getTarget(), new VersionConstraint('=', $this->version), $type, $prettyVersion);
                }
            }
            $this->$type = array_merge($links, $newLinks);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getAliasOf()
    {
        return $this->aliasOf;
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
    public function getStability()
    {
        return $this->stability;
    }

    /**
     * {@inheritDoc}
     */
    public function getPrettyVersion()
    {
        return $this->prettyVersion;
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
    public function getRequires()
    {
        return $this->requires;
    }

    /**
     * {@inheritDoc}
     */
    public function getConflicts()
    {
        return $this->conflicts;
    }

    /**
     * {@inheritDoc}
     */
    public function getProvides()
    {
        return $this->provides;
    }

    /**
     * {@inheritDoc}
     */
    public function getReplaces()
    {
        return $this->replaces;
    }

    /**
     * {@inheritDoc}
     */
    public function getDevRequires()
    {
        return $this->devRequires;
    }

    /**
     * Stores whether this is an alias created by an aliasing in the requirements of the root package or not
     *
     * Use by the policy for sorting manually aliased packages first, see #576
     *
     * @param bool $value
     *
     * @return mixed
     */
    public function setRootPackageAlias($value)
    {
        return $this->rootPackageAlias = $value;
    }

    /**
     * @see setRootPackageAlias
     * @return bool
     */
    public function isRootPackageAlias()
    {
        return $this->rootPackageAlias;
    }

    /***************************************
     * Wrappers around the aliased package *
     ***************************************/

    public function getType()
    {
        return $this->aliasOf->getType();
    }

    /**
      * {@inheritDoc}
      */
    public function getTargetDir()
    {
        return $this->aliasOf->getTargetDir();
    }

    /**
      * {@inheritDoc}
      */
    public function getExtra()
    {
        return $this->aliasOf->getExtra();
    }

    /**
      * {@inheritDoc}
      */
    public function setInstallationSource($type)
    {
        $this->aliasOf->setInstallationSource($type);
    }

    /**
      * {@inheritDoc}
      */
    public function getInstallationSource()
    {
        return $this->aliasOf->getInstallationSource();
    }

    /**
      * {@inheritDoc}
      */
    public function getSourceType()
    {
        return $this->aliasOf->getSourceType();
    }

    /**
      * {@inheritDoc}
      */
    public function getSourceUrl()
    {
        return $this->aliasOf->getSourceUrl();
    }

    /**
      * {@inheritDoc}
      */
    public function getSourceReference()
    {
        return $this->aliasOf->getSourceReference();
    }

    /**
     * {@inheritDoc}
     */
    public function setSourceReference($reference)
    {
        return $this->aliasOf->setSourceReference($reference);
    }

    /**
     * {@inheritDoc}
     */
    public function getDistType()
    {
        return $this->aliasOf->getDistType();
    }

    /**
     * {@inheritDoc}
     */
    public function getDistUrl()
    {
        return $this->aliasOf->getDistUrl();
    }

    /**
     * {@inheritDoc}
     */
    public function getDistReference()
    {
        return $this->aliasOf->getDistReference();
    }

    /**
     * {@inheritDoc}
     */
    public function getDistSha1Checksum()
    {
        return $this->aliasOf->getDistSha1Checksum();
    }

    /**
     * {@inheritDoc}
     */
    public function getScripts()
    {
        return $this->aliasOf->getScripts();
    }

    /**
     * {@inheritDoc}
     */
    public function getLicense()
    {
        return $this->aliasOf->getLicense();
    }

    /**
     * {@inheritDoc}
     */
    public function getAutoload()
    {
        return $this->aliasOf->getAutoload();
    }

    /**
     * {@inheritDoc}
     */
    public function getIncludePaths()
    {
        return $this->aliasOf->getIncludePaths();
    }

    /**
     * {@inheritDoc}
     */
    public function getRepositories()
    {
        return $this->aliasOf->getRepositories();
    }

    /**
     * {@inheritDoc}
     */
    public function getReleaseDate()
    {
        return $this->aliasOf->getReleaseDate();
    }

    /**
     * {@inheritDoc}
     */
    public function getBinaries()
    {
        return $this->aliasOf->getBinaries();
    }

    /**
     * {@inheritDoc}
     */
    public function getKeywords()
    {
        return $this->aliasOf->getKeywords();
    }

    /**
      * {@inheritDoc}
      */
    public function getDescription()
    {
        return $this->aliasOf->getDescription();
    }

    /**
      * {@inheritDoc}
      */
    public function getHomepage()
    {
        return $this->aliasOf->getHomepage();
    }

    /**
      * {@inheritDoc}
      */
    public function getSuggests()
    {
        return $this->aliasOf->getSuggests();
    }

    /**
      * {@inheritDoc}
      */
    public function getAuthors()
    {
        return $this->aliasOf->getAuthors();
    }

    /**
      * {@inheritDoc}
      */
    public function getSupport()
    {
        return $this->aliasOf->getSupport();
    }

    /**
      * {@inheritDoc}
      */
    public function getNotificationUrl()
    {
        return $this->aliasOf->getNotificationUrl();
    }

    /**
      * {@inheritDoc}
      */
    public function getArchiveExcludes()
    {
        return $this->aliasOf->getArchiveExcludes();
    }

    /**
      * {@inheritDoc}
      */
    public function __toString()
    {
        return parent::__toString().' (alias of '.$this->aliasOf->getVersion().')';
    }
}
