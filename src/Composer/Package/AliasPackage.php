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

use Composer\Semver\Constraint\Constraint;
use Composer\Package\Version\VersionParser;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class AliasPackage extends BasePackage
{
    protected $version;
    protected $prettyVersion;
    protected $dev;
    protected $rootPackageAlias = false;
    protected $stability;
    protected $hasSelfVersionRequires = false;

    /** @var BasePackage */
    protected $aliasOf;
    /** @var Link[] */
    protected $requires;
    /** @var Link[] */
    protected $devRequires;
    /** @var Link[] */
    protected $conflicts;
    /** @var Link[] */
    protected $provides;
    /** @var Link[] */
    protected $replaces;

    /**
     * All descendants' constructors should call this parent constructor
     *
     * @param BasePackage $aliasOf       The package this package is an alias of
     * @param string      $version       The version the alias must report
     * @param string      $prettyVersion The alias's non-normalized version
     */
    public function __construct(BasePackage $aliasOf, $version, $prettyVersion)
    {
        parent::__construct($aliasOf->getName());

        $this->version = $version;
        $this->prettyVersion = $prettyVersion;
        $this->aliasOf = $aliasOf;
        $this->stability = VersionParser::parseStability($version);
        $this->dev = $this->stability === 'dev';

        foreach (Link::$TYPES as $type) {
            $links = $aliasOf->{'get' . ucfirst($type)}();
            $this->$type = $this->replaceSelfVersionDependencies($links, $type);
        }
    }

    /**
     * @return BasePackage
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

    /**
     * @param Link[] $links
     * @param string $linkType
     *
     * @return Link[]
     */
    protected function replaceSelfVersionDependencies(array $links, $linkType)
    {
        // for self.version requirements, we use the original package's branch name instead, to avoid leaking the magic dev-master-alias to users
        $prettyVersion = $this->prettyVersion;
        if ($prettyVersion === VersionParser::DEFAULT_BRANCH_ALIAS) {
            $prettyVersion = $this->aliasOf->getPrettyVersion();
        }

        if (\in_array($linkType, array(Link::TYPE_CONFLICT, Link::TYPE_PROVIDE, Link::TYPE_REPLACE), true)) {
            $newLinks = array();
            foreach ($links as $link) {
                // link is self.version, but must be replacing also the replaced version
                if ('self.version' === $link->getPrettyConstraint()) {
                    $newLinks[] = new Link($link->getSource(), $link->getTarget(), $constraint = new Constraint('=', $this->version), $linkType, $prettyVersion);
                    $constraint->setPrettyString($prettyVersion);
                }
            }
            $links = array_merge($links, $newLinks);
        } else {
            foreach ($links as $index => $link) {
                if ('self.version' === $link->getPrettyConstraint()) {
                    if ($linkType === Link::TYPE_REQUIRE) {
                        $this->hasSelfVersionRequires = true;
                    }
                    $links[$index] = new Link($link->getSource(), $link->getTarget(), $constraint = new Constraint('=', $this->version), $linkType, $prettyVersion);
                    $constraint->setPrettyString($prettyVersion);
                }
            }
        }

        return $links;
    }

    public function hasSelfVersionRequires()
    {
        return $this->hasSelfVersionRequires;
    }

    public function __toString()
    {
        return parent::__toString().' ('.($this->rootPackageAlias ? 'root ' : ''). 'alias of '.$this->aliasOf->getVersion().')';
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

    public function getSourceUrls()
    {
        return $this->aliasOf->getSourceUrls();
    }

    public function getSourceReference()
    {
        return $this->aliasOf->getSourceReference();
    }

    public function setSourceReference($reference)
    {
        $this->aliasOf->setSourceReference($reference);
    }

    public function setSourceMirrors($mirrors)
    {
        $this->aliasOf->setSourceMirrors($mirrors);
    }

    public function getSourceMirrors()
    {
        return $this->aliasOf->getSourceMirrors();
    }

    public function getDistType()
    {
        return $this->aliasOf->getDistType();
    }

    public function getDistUrl()
    {
        return $this->aliasOf->getDistUrl();
    }

    public function getDistUrls()
    {
        return $this->aliasOf->getDistUrls();
    }

    public function getDistReference()
    {
        return $this->aliasOf->getDistReference();
    }

    public function setDistReference($reference)
    {
        $this->aliasOf->setDistReference($reference);
    }

    public function getDistSha1Checksum()
    {
        return $this->aliasOf->getDistSha1Checksum();
    }

    public function setTransportOptions(array $options)
    {
        $this->aliasOf->setTransportOptions($options);
    }

    public function getTransportOptions()
    {
        return $this->aliasOf->getTransportOptions();
    }

    public function setDistMirrors($mirrors)
    {
        $this->aliasOf->setDistMirrors($mirrors);
    }

    public function getDistMirrors()
    {
        return $this->aliasOf->getDistMirrors();
    }

    public function getAutoload()
    {
        return $this->aliasOf->getAutoload();
    }

    public function getDevAutoload()
    {
        return $this->aliasOf->getDevAutoload();
    }

    public function getIncludePaths()
    {
        return $this->aliasOf->getIncludePaths();
    }

    public function getReleaseDate()
    {
        return $this->aliasOf->getReleaseDate();
    }

    public function getBinaries()
    {
        return $this->aliasOf->getBinaries();
    }

    public function getSuggests()
    {
        return $this->aliasOf->getSuggests();
    }

    public function getNotificationUrl()
    {
        return $this->aliasOf->getNotificationUrl();
    }

    public function isDefaultBranch()
    {
        return $this->aliasOf->isDefaultBranch();
    }

    public function setDistUrl($url)
    {
        $this->aliasOf->setDistUrl($url);
    }

    public function setDistType($type)
    {
        $this->aliasOf->setDistType($type);
    }

    public function setSourceDistReferences($reference)
    {
        $this->aliasOf->setSourceDistReferences($reference);
    }
}
