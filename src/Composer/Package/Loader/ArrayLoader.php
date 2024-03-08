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

namespace Composer\Package\Loader;

use Composer\Package\BasePackage;
use Composer\Package\CompleteAliasPackage;
use Composer\Package\CompletePackage;
use Composer\Package\RootPackage;
use Composer\Package\PackageInterface;
use Composer\Package\CompletePackageInterface;
use Composer\Package\Link;
use Composer\Package\RootAliasPackage;
use Composer\Package\Version\VersionParser;
use Composer\Pcre\Preg;

/**
 * @author Konstantin Kudryashiv <ever.zet@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ArrayLoader implements LoaderInterface
{
    /** @var VersionParser */
    protected $versionParser;
    /** @var bool */
    protected $loadOptions;

    public function __construct(?VersionParser $parser = null, bool $loadOptions = false)
    {
        if (!$parser) {
            $parser = new VersionParser;
        }
        $this->versionParser = $parser;
        $this->loadOptions = $loadOptions;
    }

    /**
     * @inheritDoc
     */
    public function load(array $config, string $class = 'Composer\Package\CompletePackage'): BasePackage
    {
        if ($class !== 'Composer\Package\CompletePackage' && $class !== 'Composer\Package\RootPackage') {
            trigger_error('The $class arg is deprecated, please reach out to Composer maintainers ASAP if you still need this.', E_USER_DEPRECATED);
        }

        $package = $this->createObject($config, $class);

        foreach (BasePackage::$supportedLinkTypes as $type => $opts) {
            if (!isset($config[$type]) || !is_array($config[$type])) {
                continue;
            }
            $method = 'set'.ucfirst($opts['method']);
            $package->{$method}(
                $this->parseLinks(
                    $package->getName(),
                    $package->getPrettyVersion(),
                    $opts['method'],
                    $config[$type]
                )
            );
        }

        $package = $this->configureObject($package, $config);

        return $package;
    }

    /**
     * @param array<array<mixed>> $versions
     *
     * @return list<CompletePackage|CompleteAliasPackage>
     */
    public function loadPackages(array $versions): array
    {
        $packages = [];
        $linkCache = [];

        foreach ($versions as $version) {
            $package = $this->createObject($version, 'Composer\Package\CompletePackage');

            $this->configureCachedLinks($linkCache, $package, $version);
            $package = $this->configureObject($package, $version);

            $packages[] = $package;
        }

        return $packages;
    }

    /**
     * @template PackageClass of CompletePackage
     *
     * @param mixed[] $config package data
     * @param string  $class  FQCN to be instantiated
     *
     * @return CompletePackage|RootPackage
     *
     * @phpstan-param class-string<PackageClass> $class
     */
    private function createObject(array $config, string $class): CompletePackage
    {
        if (!isset($config['name'])) {
            throw new \UnexpectedValueException('Unknown package has no name defined ('.json_encode($config).').');
        }
        if (!isset($config['version']) || !is_scalar($config['version'])) {
            throw new \UnexpectedValueException('Package '.$config['name'].' has no version defined.');
        }
        if (!is_string($config['version'])) {
            $config['version'] = (string) $config['version'];
        }

        // handle already normalized versions
        if (isset($config['version_normalized']) && is_string($config['version_normalized'])) {
            $version = $config['version_normalized'];

            // handling of existing repos which need to remain composer v1 compatible, in case the version_normalized contained VersionParser::DEFAULT_BRANCH_ALIAS, we renormalize it
            if ($version === VersionParser::DEFAULT_BRANCH_ALIAS) {
                $version = $this->versionParser->normalize($config['version']);
            }
        } else {
            $version = $this->versionParser->normalize($config['version']);
        }

        return new $class($config['name'], $version, $config['version']);
    }

    /**
     * @param CompletePackage $package
     * @param mixed[]         $config package data
     *
     * @return RootPackage|RootAliasPackage|CompletePackage|CompleteAliasPackage
     */
    private function configureObject(PackageInterface $package, array $config): BasePackage
    {
        if (!$package instanceof CompletePackage) {
            throw new \LogicException('ArrayLoader expects instances of the Composer\Package\CompletePackage class to function correctly');
        }

        $package->setType(isset($config['type']) ? strtolower($config['type']) : 'library');

        if (isset($config['target-dir'])) {
            $package->setTargetDir($config['target-dir']);
        }

        if (isset($config['extra']) && \is_array($config['extra'])) {
            $package->setExtra($config['extra']);
        }

        if (isset($config['bin'])) {
            if (!\is_array($config['bin'])) {
                $config['bin'] = [$config['bin']];
            }
            foreach ($config['bin'] as $key => $bin) {
                $config['bin'][$key] = ltrim($bin, '/');
            }
            $package->setBinaries($config['bin']);
        }

        if (isset($config['installation-source'])) {
            $package->setInstallationSource($config['installation-source']);
        }

        if (isset($config['default-branch']) && $config['default-branch'] === true) {
            $package->setIsDefaultBranch(true);
        }

        if (isset($config['source'])) {
            if (!isset($config['source']['type'], $config['source']['url'], $config['source']['reference'])) {
                throw new \UnexpectedValueException(sprintf(
                    "Package %s's source key should be specified as {\"type\": ..., \"url\": ..., \"reference\": ...},\n%s given.",
                    $config['name'],
                    json_encode($config['source'])
                ));
            }
            $package->setSourceType($config['source']['type']);
            $package->setSourceUrl($config['source']['url']);
            $package->setSourceReference(isset($config['source']['reference']) ? (string) $config['source']['reference'] : null);
            if (isset($config['source']['mirrors'])) {
                $package->setSourceMirrors($config['source']['mirrors']);
            }
        }

        if (isset($config['dist'])) {
            if (!isset($config['dist']['type'], $config['dist']['url'])) {
                throw new \UnexpectedValueException(sprintf(
                    "Package %s's dist key should be specified as ".
                    "{\"type\": ..., \"url\": ..., \"reference\": ..., \"shasum\": ...},\n%s given.",
                    $config['name'],
                    json_encode($config['dist'])
                ));
            }
            $package->setDistType($config['dist']['type']);
            $package->setDistUrl($config['dist']['url']);
            $package->setDistReference(isset($config['dist']['reference']) ? (string) $config['dist']['reference'] : null);
            $package->setDistSha1Checksum($config['dist']['shasum'] ?? null);
            if (isset($config['dist']['mirrors'])) {
                $package->setDistMirrors($config['dist']['mirrors']);
            }
        }

        if (isset($config['suggest']) && \is_array($config['suggest'])) {
            foreach ($config['suggest'] as $target => $reason) {
                if ('self.version' === trim($reason)) {
                    $config['suggest'][$target] = $package->getPrettyVersion();
                }
            }
            $package->setSuggests($config['suggest']);
        }

        if (isset($config['autoload'])) {
            $package->setAutoload($config['autoload']);
        }

        if (isset($config['autoload-dev'])) {
            $package->setDevAutoload($config['autoload-dev']);
        }

        if (isset($config['include-path'])) {
            $package->setIncludePaths($config['include-path']);
        }

        if (!empty($config['time'])) {
            $time = Preg::isMatch('/^\d++$/D', $config['time']) ? '@'.$config['time'] : $config['time'];

            try {
                $date = new \DateTime($time, new \DateTimeZone('UTC'));
                $package->setReleaseDate($date);
            } catch (\Exception $e) {
            }
        }

        if (!empty($config['notification-url'])) {
            $package->setNotificationUrl($config['notification-url']);
        }

        if ($package instanceof CompletePackageInterface) {
            if (!empty($config['archive']['name'])) {
                $package->setArchiveName($config['archive']['name']);
            }
            if (!empty($config['archive']['exclude'])) {
                $package->setArchiveExcludes($config['archive']['exclude']);
            }

            if (isset($config['scripts']) && \is_array($config['scripts'])) {
                foreach ($config['scripts'] as $event => $listeners) {
                    $config['scripts'][$event] = (array) $listeners;
                }
                foreach (['composer', 'php', 'putenv'] as $reserved) {
                    if (isset($config['scripts'][$reserved])) {
                        trigger_error('The `'.$reserved.'` script name is reserved for internal use, please avoid defining it', E_USER_DEPRECATED);
                    }
                }
                $package->setScripts($config['scripts']);
            }

            if (!empty($config['description']) && \is_string($config['description'])) {
                $package->setDescription($config['description']);
            }

            if (!empty($config['homepage']) && \is_string($config['homepage'])) {
                $package->setHomepage($config['homepage']);
            }

            if (!empty($config['keywords']) && \is_array($config['keywords'])) {
                $package->setKeywords(array_map('strval', $config['keywords']));
            }

            if (!empty($config['license'])) {
                $package->setLicense(\is_array($config['license']) ? $config['license'] : [$config['license']]);
            }

            if (!empty($config['authors']) && \is_array($config['authors'])) {
                $package->setAuthors($config['authors']);
            }

            if (isset($config['support']) && \is_array($config['support'])) {
                $package->setSupport($config['support']);
            }

            if (!empty($config['funding']) && \is_array($config['funding'])) {
                $package->setFunding($config['funding']);
            }

            if (isset($config['abandoned'])) {
                $package->setAbandoned($config['abandoned']);
            }
        }

        if ($this->loadOptions && isset($config['transport-options'])) {
            $package->setTransportOptions($config['transport-options']);
        }

        if ($aliasNormalized = $this->getBranchAlias($config)) {
            $prettyAlias = Preg::replace('{(\.9{7})+}', '.x', $aliasNormalized);

            if ($package instanceof RootPackage) {
                return new RootAliasPackage($package, $aliasNormalized, $prettyAlias);
            }

            return new CompleteAliasPackage($package, $aliasNormalized, $prettyAlias);
        }

        return $package;
    }

    /**
     * @param array<string, array<string, array<int|string, array<int|string, array{string, Link}>>>> $linkCache
     * @param mixed[]                                                                             $config
     */
    private function configureCachedLinks(array &$linkCache, PackageInterface $package, array $config): void
    {
        $name = $package->getName();
        $prettyVersion = $package->getPrettyVersion();

        foreach (BasePackage::$supportedLinkTypes as $type => $opts) {
            if (isset($config[$type])) {
                $method = 'set'.ucfirst($opts['method']);

                $links = [];
                foreach ($config[$type] as $prettyTarget => $constraint) {
                    $target = strtolower($prettyTarget);

                    // recursive links are not supported
                    if ($target === $name) {
                        continue;
                    }

                    if ($constraint === 'self.version') {
                        $links[$target] = $this->createLink($name, $prettyVersion, $opts['method'], $target, $constraint);
                    } else {
                        if (!isset($linkCache[$name][$type][$target][$constraint])) {
                            $linkCache[$name][$type][$target][$constraint] = [$target, $this->createLink($name, $prettyVersion, $opts['method'], $target, $constraint)];
                        }

                        [$target, $link] = $linkCache[$name][$type][$target][$constraint];
                        $links[$target] = $link;
                    }
                }

                $package->{$method}($links);
            }
        }
    }

    /**
     * @param  string                    $source        source package name
     * @param  string                    $sourceVersion source package version (pretty version ideally)
     * @param  string                    $description   link description (e.g. requires, replaces, ..)
     * @param  array<string|int, string> $links         array of package name => constraint mappings
     *
     * @return Link[]
     *
     * @phpstan-param Link::TYPE_* $description
     */
    public function parseLinks(string $source, string $sourceVersion, string $description, array $links): array
    {
        $res = [];
        foreach ($links as $target => $constraint) {
            if (!is_string($constraint)) {
                continue;
            }
            $target = strtolower((string) $target);
            $res[$target] = $this->createLink($source, $sourceVersion, $description, $target, $constraint);
        }

        return $res;
    }

    /**
     * @param  string       $source           source package name
     * @param  string       $sourceVersion    source package version (pretty version ideally)
     * @param  Link::TYPE_* $description      link description (e.g. requires, replaces, ..)
     * @param  string       $target           target package name
     * @param  string       $prettyConstraint constraint string
     */
    private function createLink(string $source, string $sourceVersion, string $description, string $target, string $prettyConstraint): Link
    {
        if (!\is_string($prettyConstraint)) {
            throw new \UnexpectedValueException('Link constraint in '.$source.' '.$description.' > '.$target.' should be a string, got '.\gettype($prettyConstraint) . ' (' . var_export($prettyConstraint, true) . ')');
        }
        if ('self.version' === $prettyConstraint) {
            $parsedConstraint = $this->versionParser->parseConstraints($sourceVersion);
        } else {
            $parsedConstraint = $this->versionParser->parseConstraints($prettyConstraint);
        }

        return new Link($source, $target, $parsedConstraint, $description, $prettyConstraint);
    }

    /**
     * Retrieves a branch alias (dev-master => 1.0.x-dev for example) if it exists
     *
     * @param mixed[] $config the entire package config
     *
     * @return string|null normalized version of the branch alias or null if there is none
     */
    public function getBranchAlias(array $config): ?string
    {
        if (!isset($config['version']) || !is_scalar($config['version'])) {
            throw new \UnexpectedValueException('no/invalid version defined');
        }
        if (!is_string($config['version'])) {
            $config['version'] = (string) $config['version'];
        }

        if (strpos($config['version'], 'dev-') !== 0 && '-dev' !== substr($config['version'], -4)) {
            return null;
        }

        if (isset($config['extra']['branch-alias']) && \is_array($config['extra']['branch-alias'])) {
            foreach ($config['extra']['branch-alias'] as $sourceBranch => $targetBranch) {
                $sourceBranch = (string) $sourceBranch;

                // ensure it is an alias to a -dev package
                if ('-dev' !== substr($targetBranch, -4)) {
                    continue;
                }

                // normalize without -dev and ensure it's a numeric branch that is parseable
                if ($targetBranch === VersionParser::DEFAULT_BRANCH_ALIAS) {
                    $validatedTargetBranch = VersionParser::DEFAULT_BRANCH_ALIAS;
                } else {
                    $validatedTargetBranch = $this->versionParser->normalizeBranch(substr($targetBranch, 0, -4));
                }
                if ('-dev' !== substr($validatedTargetBranch, -4)) {
                    continue;
                }

                // ensure that it is the current branch aliasing itself
                if (strtolower($config['version']) !== strtolower($sourceBranch)) {
                    continue;
                }

                // If using numeric aliases ensure the alias is a valid subversion
                if (($sourcePrefix = $this->versionParser->parseNumericAliasPrefix($sourceBranch))
                    && ($targetPrefix = $this->versionParser->parseNumericAliasPrefix($targetBranch))
                    && (stripos($targetPrefix, $sourcePrefix) !== 0)
                ) {
                    continue;
                }

                return $validatedTargetBranch;
            }
        }

        if (
            isset($config['default-branch'])
            && $config['default-branch'] === true
            && false === $this->versionParser->parseNumericAliasPrefix(Preg::replace('{^v}', '', $config['version']))
        ) {
            return VersionParser::DEFAULT_BRANCH_ALIAS;
        }

        return null;
    }
}
