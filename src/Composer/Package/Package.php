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

use Composer\Package\Version\VersionParser;
use Composer\Pcre\Preg;
use Composer\Util\ComposerMirror;

/**
 * Core package definitions that are needed to resolve dependencies and install packages
 *
 * @author Nils Adermann <naderman@naderman.de>
 */
class Package extends BasePackage
{
    /** @var string */
    protected $type;
    /** @var ?string */
    protected $targetDir;
    /** @var 'source'|'dist'|null */
    protected $installationSource;
    /** @var ?string */
    protected $sourceType;
    /** @var ?string */
    protected $sourceUrl;
    /** @var ?string */
    protected $sourceReference;
    /** @var ?array<int, array{url: string, preferred: bool}> */
    protected $sourceMirrors;
    /** @var ?string */
    protected $distType;
    /** @var ?string */
    protected $distUrl;
    /** @var ?string */
    protected $distReference;
    /** @var ?string */
    protected $distSha1Checksum;
    /** @var ?array<int, array{url: string, preferred: bool}> */
    protected $distMirrors;
    /** @var string */
    protected $version;
    /** @var string */
    protected $prettyVersion;
    /** @var ?\DateTime */
    protected $releaseDate;
    /** @var mixed[] */
    protected $extra = array();
    /** @var string[] */
    protected $binaries = array();
    /** @var bool */
    protected $dev;
    /**
     * @var string
     * @phpstan-var 'stable'|'RC'|'beta'|'alpha'|'dev'
     */
    protected $stability;
    /** @var ?string */
    protected $notificationUrl;

    /** @var array<string, Link> */
    protected $requires = array();
    /** @var array<string, Link> */
    protected $conflicts = array();
    /** @var array<string, Link> */
    protected $provides = array();
    /** @var array<string, Link> */
    protected $replaces = array();
    /** @var array<string, Link> */
    protected $devRequires = array();
    /** @var array<string, string> */
    protected $suggests = array();
    /** @var array{psr-0?: array<string, string|string[]>, psr-4?: array<string, string|string[]>, classmap?: list<string>, files?: list<string>} */
    protected $autoload = array();
    /** @var array{psr-0?: array<string, string|string[]>, psr-4?: array<string, string|string[]>, classmap?: list<string>, files?: list<string>} */
    protected $devAutoload = array();
    /** @var string[] */
    protected $includePaths = array();
    /** @var bool */
    protected $isDefaultBranch = false;
    /** @var mixed[] */
    protected $transportOptions = array();

    /**
     * Creates a new in memory package.
     *
     * @param string $name          The package's name
     * @param string $version       The package's version
     * @param string $prettyVersion The package's non-normalized version
     */
    public function __construct($name, $version, $prettyVersion)
    {
        parent::__construct($name);

        $this->version = $version;
        $this->prettyVersion = $prettyVersion;

        $this->stability = VersionParser::parseStability($version);
        $this->dev = $this->stability === 'dev';
    }

    /**
     * @inheritDoc
     */
    public function isDev()
    {
        return $this->dev;
    }

    /**
     * @param string $type
     *
     * @return void
     */
    public function setType($type)
    {
        $this->type = $type;
    }

    /**
     * @inheritDoc
     */
    public function getType()
    {
        return $this->type ?: 'library';
    }

    /**
     * @inheritDoc
     */
    public function getStability()
    {
        return $this->stability;
    }

    /**
     * @param string $targetDir
     *
     * @return void
     */
    public function setTargetDir($targetDir)
    {
        $this->targetDir = $targetDir;
    }

    /**
     * @inheritDoc
     */
    public function getTargetDir()
    {
        if (null === $this->targetDir) {
            return null;
        }

        return ltrim(Preg::replace('{ (?:^|[\\\\/]+) \.\.? (?:[\\\\/]+|$) (?:\.\.? (?:[\\\\/]+|$) )*}x', '/', $this->targetDir), '/');
    }

    /**
     * @param mixed[] $extra
     *
     * @return void
     */
    public function setExtra(array $extra)
    {
        $this->extra = $extra;
    }

    /**
     * @inheritDoc
     */
    public function getExtra()
    {
        return $this->extra;
    }

    /**
     * @param string[] $binaries
     *
     * @return void
     */
    public function setBinaries(array $binaries)
    {
        $this->binaries = $binaries;
    }

    /**
     * @inheritDoc
     */
    public function getBinaries()
    {
        return $this->binaries;
    }

    /**
     * @inheritDoc
     *
     * @return void
     */
    public function setInstallationSource($type)
    {
        $this->installationSource = $type;
    }

    /**
     * @inheritDoc
     */
    public function getInstallationSource()
    {
        return $this->installationSource;
    }

    /**
     * @param string $type
     *
     * @return void
     */
    public function setSourceType($type)
    {
        $this->sourceType = $type;
    }

    /**
     * @inheritDoc
     */
    public function getSourceType()
    {
        return $this->sourceType;
    }

    /**
     * @param string $url
     *
     * @return void
     */
    public function setSourceUrl($url)
    {
        $this->sourceUrl = $url;
    }

    /**
     * @inheritDoc
     */
    public function getSourceUrl()
    {
        return $this->sourceUrl;
    }

    /**
     * @param string $reference
     *
     * @return void
     */
    public function setSourceReference($reference)
    {
        $this->sourceReference = $reference;
    }

    /**
     * @inheritDoc
     */
    public function getSourceReference()
    {
        return $this->sourceReference;
    }

    /**
     * @inheritDoc
     *
     * @return void
     */
    public function setSourceMirrors($mirrors)
    {
        $this->sourceMirrors = $mirrors;
    }

    /**
     * @inheritDoc
     */
    public function getSourceMirrors()
    {
        return $this->sourceMirrors;
    }

    /**
     * @inheritDoc
     */
    public function getSourceUrls()
    {
        return $this->getUrls($this->sourceUrl, $this->sourceMirrors, $this->sourceReference, $this->sourceType, 'source');
    }

    /**
     * @param string $type
     *
     * @return void
     */
    public function setDistType($type)
    {
        $this->distType = $type;
    }

    /**
     * @inheritDoc
     */
    public function getDistType()
    {
        return $this->distType;
    }

    /**
     * @param string $url
     *
     * @return void
     */
    public function setDistUrl($url)
    {
        $this->distUrl = $url;
    }

    /**
     * @inheritDoc
     */
    public function getDistUrl()
    {
        return $this->distUrl;
    }

    /**
     * @param string $reference
     *
     * @return void
     */
    public function setDistReference($reference)
    {
        $this->distReference = $reference;
    }

    /**
     * @inheritDoc
     */
    public function getDistReference()
    {
        return $this->distReference;
    }

    /**
     * @param string $sha1checksum
     *
     * @return void
     */
    public function setDistSha1Checksum($sha1checksum)
    {
        $this->distSha1Checksum = $sha1checksum;
    }

    /**
     * @inheritDoc
     */
    public function getDistSha1Checksum()
    {
        return $this->distSha1Checksum;
    }

    /**
     * @inheritDoc
     *
     * @return void
     */
    public function setDistMirrors($mirrors)
    {
        $this->distMirrors = $mirrors;
    }

    /**
     * @inheritDoc
     */
    public function getDistMirrors()
    {
        return $this->distMirrors;
    }

    /**
     * @inheritDoc
     */
    public function getDistUrls()
    {
        return $this->getUrls($this->distUrl, $this->distMirrors, $this->distReference, $this->distType, 'dist');
    }

    /**
     * @inheritDoc
     */
    public function getTransportOptions()
    {
        return $this->transportOptions;
    }

    /**
     * @inheritDoc
     */
    public function setTransportOptions(array $options)
    {
        $this->transportOptions = $options;
    }

    /**
     * @inheritDoc
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * @inheritDoc
     */
    public function getPrettyVersion()
    {
        return $this->prettyVersion;
    }

    /**
     * Set the releaseDate
     *
     * @param \DateTime $releaseDate
     *
     * @return void
     */
    public function setReleaseDate(\DateTime $releaseDate)
    {
        $this->releaseDate = $releaseDate;
    }

    /**
     * @inheritDoc
     */
    public function getReleaseDate()
    {
        return $this->releaseDate;
    }

    /**
     * Set the required packages
     *
     * @param array<string, Link> $requires A set of package links
     *
     * @return void
     */
    public function setRequires(array $requires)
    {
        if (isset($requires[0])) { // @phpstan-ignore-line
            $requires = $this->convertLinksToMap($requires, 'setRequires');
        }

        $this->requires = $requires;
    }

    /**
     * @inheritDoc
     */
    public function getRequires()
    {
        return $this->requires;
    }

    /**
     * Set the conflicting packages
     *
     * @param array<string, Link> $conflicts A set of package links
     *
     * @return void
     */
    public function setConflicts(array $conflicts)
    {
        if (isset($conflicts[0])) { // @phpstan-ignore-line
            $conflicts = $this->convertLinksToMap($conflicts, 'setConflicts');
        }

        $this->conflicts = $conflicts;
    }

    /**
     * @inheritDoc
     * @return array<string, Link>
     */
    public function getConflicts()
    {
        return $this->conflicts;
    }

    /**
     * Set the provided virtual packages
     *
     * @param array<string, Link> $provides A set of package links
     *
     * @return void
     */
    public function setProvides(array $provides)
    {
        if (isset($provides[0])) { // @phpstan-ignore-line
            $provides = $this->convertLinksToMap($provides, 'setProvides');
        }

        $this->provides = $provides;
    }

    /**
     * @inheritDoc
     * @return array<string, Link>
     */
    public function getProvides()
    {
        return $this->provides;
    }

    /**
     * Set the packages this one replaces
     *
     * @param array<string, Link> $replaces A set of package links
     *
     * @return void
     */
    public function setReplaces(array $replaces)
    {
        if (isset($replaces[0])) { // @phpstan-ignore-line
            $replaces = $this->convertLinksToMap($replaces, 'setReplaces');
        }

        $this->replaces = $replaces;
    }

    /**
     * @inheritDoc
     * @return array<string, Link>
     */
    public function getReplaces()
    {
        return $this->replaces;
    }

    /**
     * Set the recommended packages
     *
     * @param array<string, Link> $devRequires A set of package links
     *
     * @return void
     */
    public function setDevRequires(array $devRequires)
    {
        if (isset($devRequires[0])) { // @phpstan-ignore-line
            $devRequires = $this->convertLinksToMap($devRequires, 'setDevRequires');
        }

        $this->devRequires = $devRequires;
    }

    /**
     * @inheritDoc
     */
    public function getDevRequires()
    {
        return $this->devRequires;
    }

    /**
     * Set the suggested packages
     *
     * @param array<string, string> $suggests A set of package names/comments
     *
     * @return void
     */
    public function setSuggests(array $suggests)
    {
        $this->suggests = $suggests;
    }

    /**
     * @inheritDoc
     */
    public function getSuggests()
    {
        return $this->suggests;
    }

    /**
     * Set the autoload mapping
     *
     * @param array $autoload Mapping of autoloading rules
     *
     * @return void
     *
     * @phpstan-param array{psr-0?: array<string, string|string[]>, psr-4?: array<string, string|string[]>, classmap?: list<string>, files?: list<string>} $autoload
     */
    public function setAutoload(array $autoload)
    {
        $this->autoload = $autoload;
    }

    /**
     * @inheritDoc
     */
    public function getAutoload()
    {
        return $this->autoload;
    }

    /**
     * Set the dev autoload mapping
     *
     * @param array $devAutoload Mapping of dev autoloading rules
     *
     * @return void
     *
     * @phpstan-param array{psr-0?: array<string, string|string[]>, psr-4?: array<string, string|string[]>, classmap?: list<string>, files?: list<string>} $devAutoload
     */
    public function setDevAutoload(array $devAutoload)
    {
        $this->devAutoload = $devAutoload;
    }

    /**
     * @inheritDoc
     */
    public function getDevAutoload()
    {
        return $this->devAutoload;
    }

    /**
     * Sets the list of paths added to PHP's include path.
     *
     * @param string[] $includePaths List of directories.
     *
     * @return void
     */
    public function setIncludePaths(array $includePaths)
    {
        $this->includePaths = $includePaths;
    }

    /**
     * @inheritDoc
     */
    public function getIncludePaths()
    {
        return $this->includePaths;
    }

    /**
     * Sets the notification URL
     *
     * @param string $notificationUrl
     *
     * @return void
     */
    public function setNotificationUrl($notificationUrl)
    {
        $this->notificationUrl = $notificationUrl;
    }

    /**
     * @inheritDoc
     */
    public function getNotificationUrl()
    {
        return $this->notificationUrl;
    }

    /**
     * @param bool $defaultBranch
     *
     * @return void
     */
    public function setIsDefaultBranch($defaultBranch)
    {
        $this->isDefaultBranch = $defaultBranch;
    }

    /**
     * @inheritDoc
     */
    public function isDefaultBranch()
    {
        return $this->isDefaultBranch;
    }

    /**
     * @inheritDoc
     */
    public function setSourceDistReferences($reference)
    {
        $this->setSourceReference($reference);

        // only bitbucket, github and gitlab have auto generated dist URLs that easily allow replacing the reference in the dist URL
        // TODO generalize this a bit for self-managed/on-prem versions? Some kind of replace token in dist urls which allow this?
        if (
            $this->getDistUrl() !== null
            && Preg::isMatch('{^https?://(?:(?:www\.)?bitbucket\.org|(api\.)?github\.com|(?:www\.)?gitlab\.com)/}i', $this->getDistUrl())
        ) {
            $this->setDistReference($reference);
            $this->setDistUrl(Preg::replace('{(?<=/|sha=)[a-f0-9]{40}(?=/|$)}i', $reference, $this->getDistUrl()));
        } elseif ($this->getDistReference()) { // update the dist reference if there was one, but if none was provided ignore it
            $this->setDistReference($reference);
        }
    }

    /**
     * Replaces current version and pretty version with passed values.
     * It also sets stability.
     *
     * @param string $version       The package's normalized version
     * @param string $prettyVersion The package's non-normalized version
     *
     * @return void
     */
    public function replaceVersion($version, $prettyVersion)
    {
        $this->version = $version;
        $this->prettyVersion = $prettyVersion;

        $this->stability = VersionParser::parseStability($version);
        $this->dev = $this->stability === 'dev';
    }

    /**
     * @param string|null  $url
     * @param mixed[]|null $mirrors
     * @param string|null  $ref
     * @param string|null  $type
     * @param string       $urlType
     *
     * @return string[]
     *
     * @phpstan-param list<array{url: string, preferred: bool}>|null $mirrors
     */
    protected function getUrls($url, $mirrors, $ref, $type, $urlType)
    {
        if (!$url) {
            return array();
        }

        if ($urlType === 'dist' && false !== strpos($url, '%')) {
            $url = ComposerMirror::processUrl($url, $this->name, $this->version, $ref, $type, $this->prettyVersion);
        }

        $urls = array($url);
        if ($mirrors) {
            foreach ($mirrors as $mirror) {
                if ($urlType === 'dist') {
                    $mirrorUrl = ComposerMirror::processUrl($mirror['url'], $this->name, $this->version, $ref, $type, $this->prettyVersion);
                } elseif ($urlType === 'source' && $type === 'git') {
                    $mirrorUrl = ComposerMirror::processGitUrl($mirror['url'], $this->name, $url, $type);
                } elseif ($urlType === 'source' && $type === 'hg') {
                    $mirrorUrl = ComposerMirror::processHgUrl($mirror['url'], $this->name, $url, $type);
                } else {
                    continue;
                }
                if (!\in_array($mirrorUrl, $urls)) {
                    $func = $mirror['preferred'] ? 'array_unshift' : 'array_push';
                    $func($urls, $mirrorUrl);
                }
            }
        }

        return $urls;
    }

    /**
     * @param  array<int, Link> $links
     * @param  string $source
     * @return array<string, Link>
     */
    private function convertLinksToMap(array $links, $source)
    {
        trigger_error('Package::'.$source.' must be called with a map of lowercased package name => Link object, got a indexed array, this is deprecated and you should fix your usage.');
        $newLinks = array();
        foreach ($links as $link) {
            $newLinks[$link->getTarget()] = $link;
        }

        return $newLinks;
    }
}
