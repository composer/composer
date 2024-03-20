<?php declare(strict_types=1);

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
 *
 * @phpstan-import-type AutoloadRules from PackageInterface
 * @phpstan-import-type DevAutoloadRules from PackageInterface
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
    /** @var ?list<array{url: non-empty-string, preferred: bool}> */
    protected $sourceMirrors;
    /** @var ?non-empty-string */
    protected $distType;
    /** @var ?non-empty-string */
    protected $distUrl;
    /** @var ?string */
    protected $distReference;
    /** @var ?string */
    protected $distSha1Checksum;
    /** @var ?list<array{url: non-empty-string, preferred: bool}> */
    protected $distMirrors;
    /** @var string */
    protected $version;
    /** @var string */
    protected $prettyVersion;
    /** @var ?\DateTimeInterface */
    protected $releaseDate;
    /** @var mixed[] */
    protected $extra = [];
    /** @var string[] */
    protected $binaries = [];
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
    protected $requires = [];
    /** @var array<string, Link> */
    protected $conflicts = [];
    /** @var array<string, Link> */
    protected $provides = [];
    /** @var array<string, Link> */
    protected $replaces = [];
    /** @var array<string, Link> */
    protected $devRequires = [];
    /** @var array<string, string> */
    protected $suggests = [];
    /**
     * @var array
     * @phpstan-var AutoloadRules
     */
    protected $autoload = [];
    /**
     * @var array
     * @phpstan-var DevAutoloadRules
     */
    protected $devAutoload = [];
    /** @var string[] */
    protected $includePaths = [];
    /** @var bool */
    protected $isDefaultBranch = false;
    /** @var mixed[] */
    protected $transportOptions = [];
    /** @var array{priority?: int, configure-options?: list<array{name: string, description?: string}>}|null */
    protected $phpExt = null;

    /**
     * Creates a new in memory package.
     *
     * @param string $name          The package's name
     * @param string $version       The package's version
     * @param string $prettyVersion The package's non-normalized version
     */
    public function __construct(string $name, string $version, string $prettyVersion)
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
    public function isDev(): bool
    {
        return $this->dev;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    /**
     * @inheritDoc
     */
    public function getType(): string
    {
        return $this->type ?: 'library';
    }

    /**
     * @inheritDoc
     */
    public function getStability(): string
    {
        return $this->stability;
    }

    public function setTargetDir(?string $targetDir): void
    {
        $this->targetDir = $targetDir;
    }

    /**
     * @inheritDoc
     */
    public function getTargetDir(): ?string
    {
        if (null === $this->targetDir) {
            return null;
        }

        return ltrim(Preg::replace('{ (?:^|[\\\\/]+) \.\.? (?:[\\\\/]+|$) (?:\.\.? (?:[\\\\/]+|$) )*}x', '/', $this->targetDir), '/');
    }

    /**
     * @param mixed[] $extra
     */
    public function setExtra(array $extra): void
    {
        $this->extra = $extra;
    }

    /**
     * @inheritDoc
     */
    public function getExtra(): array
    {
        return $this->extra;
    }

    /**
     * @param string[] $binaries
     */
    public function setBinaries(array $binaries): void
    {
        $this->binaries = $binaries;
    }

    /**
     * @inheritDoc
     */
    public function getBinaries(): array
    {
        return $this->binaries;
    }

    /**
     * @inheritDoc
     */
    public function setInstallationSource(?string $type): void
    {
        $this->installationSource = $type;
    }

    /**
     * @inheritDoc
     */
    public function getInstallationSource(): ?string
    {
        return $this->installationSource;
    }

    public function setSourceType(?string $type): void
    {
        $this->sourceType = $type;
    }

    /**
     * @inheritDoc
     */
    public function getSourceType(): ?string
    {
        return $this->sourceType;
    }

    public function setSourceUrl(?string $url): void
    {
        $this->sourceUrl = $url;
    }

    /**
     * @inheritDoc
     */
    public function getSourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    public function setSourceReference(?string $reference): void
    {
        $this->sourceReference = $reference;
    }

    /**
     * @inheritDoc
     */
    public function getSourceReference(): ?string
    {
        return $this->sourceReference;
    }

    public function setSourceMirrors(?array $mirrors): void
    {
        $this->sourceMirrors = $mirrors;
    }

    /**
     * @inheritDoc
     */
    public function getSourceMirrors(): ?array
    {
        return $this->sourceMirrors;
    }

    /**
     * @inheritDoc
     */
    public function getSourceUrls(): array
    {
        return $this->getUrls($this->sourceUrl, $this->sourceMirrors, $this->sourceReference, $this->sourceType, 'source');
    }

    /**
     * @param string $type
     */
    public function setDistType(?string $type): void
    {
        $this->distType = $type === '' ? null : $type;
    }

    /**
     * @inheritDoc
     */
    public function getDistType(): ?string
    {
        return $this->distType;
    }

    /**
     * @param string|null $url
     */
    public function setDistUrl(?string $url): void
    {
        $this->distUrl = $url === '' ? null : $url;
    }

    /**
     * @inheritDoc
     */
    public function getDistUrl(): ?string
    {
        return $this->distUrl;
    }

    /**
     * @param string $reference
     */
    public function setDistReference(?string $reference): void
    {
        $this->distReference = $reference;
    }

    /**
     * @inheritDoc
     */
    public function getDistReference(): ?string
    {
        return $this->distReference;
    }

    /**
     * @param string $sha1checksum
     */
    public function setDistSha1Checksum(?string $sha1checksum): void
    {
        $this->distSha1Checksum = $sha1checksum;
    }

    /**
     * @inheritDoc
     */
    public function getDistSha1Checksum(): ?string
    {
        return $this->distSha1Checksum;
    }

    public function setDistMirrors(?array $mirrors): void
    {
        $this->distMirrors = $mirrors;
    }

    /**
     * @inheritDoc
     */
    public function getDistMirrors(): ?array
    {
        return $this->distMirrors;
    }

    /**
     * @inheritDoc
     */
    public function getDistUrls(): array
    {
        return $this->getUrls($this->distUrl, $this->distMirrors, $this->distReference, $this->distType, 'dist');
    }

    /**
     * @inheritDoc
     */
    public function getTransportOptions(): array
    {
        return $this->transportOptions;
    }

    /**
     * @inheritDoc
     */
    public function setTransportOptions(array $options): void
    {
        $this->transportOptions = $options;
    }

    /**
     * @inheritDoc
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * @inheritDoc
     */
    public function getPrettyVersion(): string
    {
        return $this->prettyVersion;
    }

    public function setReleaseDate(?\DateTimeInterface $releaseDate): void
    {
        $this->releaseDate = $releaseDate;
    }

    /**
     * @inheritDoc
     */
    public function getReleaseDate(): ?\DateTimeInterface
    {
        return $this->releaseDate;
    }

    /**
     * Set the required packages
     *
     * @param array<string, Link> $requires A set of package links
     */
    public function setRequires(array $requires): void
    {
        if (isset($requires[0])) { // @phpstan-ignore-line
            $requires = $this->convertLinksToMap($requires, 'setRequires');
        }

        $this->requires = $requires;
    }

    /**
     * @inheritDoc
     */
    public function getRequires(): array
    {
        return $this->requires;
    }

    /**
     * Set the conflicting packages
     *
     * @param array<string, Link> $conflicts A set of package links
     */
    public function setConflicts(array $conflicts): void
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
    public function getConflicts(): array
    {
        return $this->conflicts;
    }

    /**
     * Set the provided virtual packages
     *
     * @param array<string, Link> $provides A set of package links
     */
    public function setProvides(array $provides): void
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
    public function getProvides(): array
    {
        return $this->provides;
    }

    /**
     * Set the packages this one replaces
     *
     * @param array<string, Link> $replaces A set of package links
     */
    public function setReplaces(array $replaces): void
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
    public function getReplaces(): array
    {
        return $this->replaces;
    }

    /**
     * Set the recommended packages
     *
     * @param array<string, Link> $devRequires A set of package links
     */
    public function setDevRequires(array $devRequires): void
    {
        if (isset($devRequires[0])) { // @phpstan-ignore-line
            $devRequires = $this->convertLinksToMap($devRequires, 'setDevRequires');
        }

        $this->devRequires = $devRequires;
    }

    /**
     * @inheritDoc
     */
    public function getDevRequires(): array
    {
        return $this->devRequires;
    }

    /**
     * Set the suggested packages
     *
     * @param array<string, string> $suggests A set of package names/comments
     */
    public function setSuggests(array $suggests): void
    {
        $this->suggests = $suggests;
    }

    /**
     * @inheritDoc
     */
    public function getSuggests(): array
    {
        return $this->suggests;
    }

    /**
     * Set the autoload mapping
     *
     * @param array $autoload Mapping of autoloading rules
     *
     * @phpstan-param AutoloadRules $autoload
     */
    public function setAutoload(array $autoload): void
    {
        $this->autoload = $autoload;
    }

    /**
     * @inheritDoc
     */
    public function getAutoload(): array
    {
        return $this->autoload;
    }

    /**
     * Set the dev autoload mapping
     *
     * @param array $devAutoload Mapping of dev autoloading rules
     *
     * @phpstan-param DevAutoloadRules $devAutoload
     */
    public function setDevAutoload(array $devAutoload): void
    {
        $this->devAutoload = $devAutoload;
    }

    /**
     * @inheritDoc
     */
    public function getDevAutoload(): array
    {
        return $this->devAutoload;
    }

    /**
     * Sets the list of paths added to PHP's include path.
     *
     * @param string[] $includePaths List of directories.
     */
    public function setIncludePaths(array $includePaths): void
    {
        $this->includePaths = $includePaths;
    }

    /**
     * @inheritDoc
     */
    public function getIncludePaths(): array
    {
        return $this->includePaths;
    }

    /**
     * Sets the list of paths added to PHP's include path.
     *
     * @param array{priority?: int, configure-options?: list<array{name: string, description?: string}>}|null $phpExt List of directories.
     */
    public function setPhpExt(?array $phpExt): void
    {
        $this->phpExt = $phpExt;
    }

    /**
     * @inheritDoc
     */
    public function getPhpExt(): ?array
    {
        return $this->phpExt;
    }

    /**
     * Sets the notification URL
     */
    public function setNotificationUrl(string $notificationUrl): void
    {
        $this->notificationUrl = $notificationUrl;
    }

    /**
     * @inheritDoc
     */
    public function getNotificationUrl(): ?string
    {
        return $this->notificationUrl;
    }

    public function setIsDefaultBranch(bool $defaultBranch): void
    {
        $this->isDefaultBranch = $defaultBranch;
    }

    /**
     * @inheritDoc
     */
    public function isDefaultBranch(): bool
    {
        return $this->isDefaultBranch;
    }

    /**
     * @inheritDoc
     */
    public function setSourceDistReferences(string $reference): void
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
     */
    public function replaceVersion(string $version, string $prettyVersion): void
    {
        $this->version = $version;
        $this->prettyVersion = $prettyVersion;

        $this->stability = VersionParser::parseStability($version);
        $this->dev = $this->stability === 'dev';
    }

    /**
     * @param mixed[]|null $mirrors
     *
     * @return list<non-empty-string>
     *
     * @phpstan-param list<array{url: non-empty-string, preferred: bool}>|null $mirrors
     */
    protected function getUrls(?string $url, ?array $mirrors, ?string $ref, ?string $type, string $urlType): array
    {
        if (!$url) {
            return [];
        }

        if ($urlType === 'dist' && false !== strpos($url, '%')) {
            $url = ComposerMirror::processUrl($url, $this->name, $this->version, $ref, $type, $this->prettyVersion);
        }

        $urls = [$url];
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
     * @return array<string, Link>
     */
    private function convertLinksToMap(array $links, string $source): array
    {
        trigger_error('Package::'.$source.' must be called with a map of lowercased package name => Link object, got a indexed array, this is deprecated and you should fix your usage.');
        $newLinks = [];
        foreach ($links as $link) {
            $newLinks[$link->getTarget()] = $link;
        }

        return $newLinks;
    }
}
