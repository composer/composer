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

namespace Composer\Repository;

use Composer\Downloader\TransportException;
use Composer\Pcre\Preg;
use Composer\Repository\Vcs\VcsDriverInterface;
use Composer\Package\Version\VersionParser;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Loader\ValidatingArrayLoader;
use Composer\Package\Loader\InvalidPackageException;
use Composer\Package\Loader\LoaderInterface;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Util\ProcessExecutor;
use Composer\Util\HttpDownloader;
use Composer\Util\Url;
use Composer\Semver\Constraint\Constraint;
use Composer\IO\IOInterface;
use Composer\Config;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class VcsRepository extends ArrayRepository implements ConfigurableRepositoryInterface
{
    /** @var string */
    protected $url;
    /** @var ?string */
    protected $packageName;
    /** @var bool */
    protected $isVerbose;
    /** @var bool */
    protected $isVeryVerbose;
    /** @var IOInterface */
    protected $io;
    /** @var Config */
    protected $config;
    /** @var VersionParser */
    protected $versionParser;
    /** @var string */
    protected $type;
    /** @var ?LoaderInterface */
    protected $loader;
    /** @var array<string, mixed> */
    protected $repoConfig;
    /** @var HttpDownloader */
    protected $httpDownloader;
    /** @var ProcessExecutor */
    protected $processExecutor;
    /** @var bool */
    protected $branchErrorOccurred = false;
    /** @var array<string, class-string<VcsDriverInterface>> */
    private $drivers;
    /** @var ?VcsDriverInterface */
    private $driver;
    /** @var ?VersionCacheInterface */
    private $versionCache;
    /** @var string[] */
    private $emptyReferences = array();
    /** @var array<'tags'|'branches', array<string, TransportException>> */
    private $versionTransportExceptions = array();

    /**
     * @param array{url: string, type?: string}&array<string, mixed> $repoConfig
     * @param array<string, class-string<VcsDriverInterface>>|null $drivers
     */
    public function __construct(array $repoConfig, IOInterface $io, Config $config, HttpDownloader $httpDownloader, EventDispatcher $dispatcher = null, ProcessExecutor $process = null, array $drivers = null, VersionCacheInterface $versionCache = null)
    {
        parent::__construct();
        $this->drivers = $drivers ?: array(
            'github' => 'Composer\Repository\Vcs\GitHubDriver',
            'gitlab' => 'Composer\Repository\Vcs\GitLabDriver',
            'bitbucket' => 'Composer\Repository\Vcs\GitBitbucketDriver',
            'git-bitbucket' => 'Composer\Repository\Vcs\GitBitbucketDriver',
            'git' => 'Composer\Repository\Vcs\GitDriver',
            'hg' => 'Composer\Repository\Vcs\HgDriver',
            'perforce' => 'Composer\Repository\Vcs\PerforceDriver',
            'fossil' => 'Composer\Repository\Vcs\FossilDriver',
            // svn must be last because identifying a subversion server for sure is practically impossible
            'svn' => 'Composer\Repository\Vcs\SvnDriver',
        );

        $this->url = $repoConfig['url'];
        $this->io = $io;
        $this->type = $repoConfig['type'] ?? 'vcs';
        $this->isVerbose = $io->isVerbose();
        $this->isVeryVerbose = $io->isVeryVerbose();
        $this->config = $config;
        $this->repoConfig = $repoConfig;
        $this->versionCache = $versionCache;
        $this->httpDownloader = $httpDownloader;
        $this->processExecutor = $process ?? new ProcessExecutor($io);
    }

    public function getRepoName()
    {
        $driverClass = get_class($this->getDriver());
        $driverType = array_search($driverClass, $this->drivers);
        if (!$driverType) {
            $driverType = $driverClass;
        }

        return 'vcs repo ('.$driverType.' '.Url::sanitize($this->url).')';
    }

    public function getRepoConfig()
    {
        return $this->repoConfig;
    }

    /**
     * @return void
     */
    public function setLoader(LoaderInterface $loader): void
    {
        $this->loader = $loader;
    }

    /**
     * @return VcsDriverInterface|null
     */
    public function getDriver(): ?VcsDriverInterface
    {
        if ($this->driver) {
            return $this->driver;
        }

        if (isset($this->drivers[$this->type])) {
            $class = $this->drivers[$this->type];
            $this->driver = new $class($this->repoConfig, $this->io, $this->config, $this->httpDownloader, $this->processExecutor);
            $this->driver->initialize();

            return $this->driver;
        }

        foreach ($this->drivers as $driver) {
            if ($driver::supports($this->io, $this->config, $this->url)) {
                $this->driver = new $driver($this->repoConfig, $this->io, $this->config, $this->httpDownloader, $this->processExecutor);
                $this->driver->initialize();

                return $this->driver;
            }
        }

        foreach ($this->drivers as $driver) {
            if ($driver::supports($this->io, $this->config, $this->url, true)) {
                $this->driver = new $driver($this->repoConfig, $this->io, $this->config, $this->httpDownloader, $this->processExecutor);
                $this->driver->initialize();

                return $this->driver;
            }
        }

        return null;
    }

    /**
     * @return bool
     */
    public function hadInvalidBranches(): bool
    {
        return $this->branchErrorOccurred;
    }

    /**
     * @return string[]
     */
    public function getEmptyReferences(): array
    {
        return $this->emptyReferences;
    }

    /**
     * @return array<'tags'|'branches', array<string, TransportException>>
     */
    public function getVersionTransportExceptions(): array
    {
        return $this->versionTransportExceptions;
    }

    protected function initialize(): void
    {
        parent::initialize();

        $isVerbose = $this->isVerbose;
        $isVeryVerbose = $this->isVeryVerbose;

        $driver = $this->getDriver();
        if (!$driver) {
            throw new \InvalidArgumentException('No driver found to handle VCS repository '.$this->url);
        }

        $this->versionParser = new VersionParser;
        if (!$this->loader) {
            $this->loader = new ArrayLoader($this->versionParser);
        }

        $hasRootIdentifierComposerJson = false;
        try {
            $hasRootIdentifierComposerJson = $driver->hasComposerFile($driver->getRootIdentifier());
            if ($hasRootIdentifierComposerJson) {
                $data = $driver->getComposerInformation($driver->getRootIdentifier());
                $this->packageName = !empty($data['name']) ? $data['name'] : null;
            }
        } catch (\Exception $e) {
            if ($e instanceof TransportException && $this->shouldRethrowTransportException($e)) {
                throw $e;
            }

            if ($isVeryVerbose) {
                $this->io->writeError('<error>Skipped parsing '.$driver->getRootIdentifier().', '.$e->getMessage().'</error>');
            }
        }

        foreach ($driver->getTags() as $tag => $identifier) {
            $tag = (string) $tag;
            $msg = 'Reading composer.json of <info>' . ($this->packageName ?: $this->url) . '</info> (<comment>' . $tag . '</comment>)';
            if ($isVeryVerbose) {
                $this->io->writeError($msg);
            } elseif ($isVerbose) {
                $this->io->overwriteError($msg, false);
            }

            // strip the release- prefix from tags if present
            $tag = str_replace('release-', '', $tag);

            $cachedPackage = $this->getCachedPackageVersion($tag, $identifier, $isVerbose, $isVeryVerbose);
            if ($cachedPackage) {
                $this->addPackage($cachedPackage);

                continue;
            }
            if ($cachedPackage === false) {
                $this->emptyReferences[] = $identifier;

                continue;
            }

            if (!$parsedTag = $this->validateTag($tag)) {
                if ($isVeryVerbose) {
                    $this->io->writeError('<warning>Skipped tag '.$tag.', invalid tag name</warning>');
                }
                continue;
            }

            try {
                $data = $driver->getComposerInformation($identifier);
                if (null === $data) {
                    if ($isVeryVerbose) {
                        $this->io->writeError('<warning>Skipped tag '.$tag.', no composer file</warning>');
                    }
                    $this->emptyReferences[] = $identifier;
                    continue;
                }

                // manually versioned package
                if (isset($data['version'])) {
                    $data['version_normalized'] = $this->versionParser->normalize($data['version']);
                } else {
                    // auto-versioned package, read value from tag
                    $data['version'] = $tag;
                    $data['version_normalized'] = $parsedTag;
                }

                // make sure tag packages have no -dev flag
                $data['version'] = Preg::replace('{[.-]?dev$}i', '', $data['version']);
                $data['version_normalized'] = Preg::replace('{(^dev-|[.-]?dev$)}i', '', $data['version_normalized']);

                // make sure tag do not contain the default-branch marker
                unset($data['default-branch']);

                // broken package, version doesn't match tag
                if ($data['version_normalized'] !== $parsedTag) {
                    if ($isVeryVerbose) {
                        if (Preg::isMatch('{(^dev-|[.-]?dev$)}i', $parsedTag)) {
                            $this->io->writeError('<warning>Skipped tag '.$tag.', invalid tag name, tags can not use dev prefixes or suffixes</warning>');
                        } else {
                            $this->io->writeError('<warning>Skipped tag '.$tag.', tag ('.$parsedTag.') does not match version ('.$data['version_normalized'].') in composer.json</warning>');
                        }
                    }
                    continue;
                }

                $tagPackageName = $this->packageName ?: ($data['name'] ?? '');
                if ($existingPackage = $this->findPackage($tagPackageName, $data['version_normalized'])) {
                    if ($isVeryVerbose) {
                        $this->io->writeError('<warning>Skipped tag '.$tag.', it conflicts with an another tag ('.$existingPackage->getPrettyVersion().') as both resolve to '.$data['version_normalized'].' internally</warning>');
                    }
                    continue;
                }

                if ($isVeryVerbose) {
                    $this->io->writeError('Importing tag '.$tag.' ('.$data['version_normalized'].')');
                }

                $this->addPackage($this->loader->load($this->preProcess($driver, $data, $identifier)));
            } catch (\Exception $e) {
                if ($e instanceof TransportException) {
                    $this->versionTransportExceptions['tags'][$tag] = $e;
                    if ($e->getCode() === 404) {
                        $this->emptyReferences[] = $identifier;
                    }
                    if ($this->shouldRethrowTransportException($e)) {
                        throw $e;
                    }
                }
                if ($isVeryVerbose) {
                    $this->io->writeError('<warning>Skipped tag '.$tag.', '.($e instanceof TransportException ? 'no composer file was found (' . $e->getCode() . ' HTTP status code)' : $e->getMessage()).'</warning>');
                }
                continue;
            }
        }

        if (!$isVeryVerbose) {
            $this->io->overwriteError('', false);
        }

        $branches = $driver->getBranches();
        // make sure the root identifier branch gets loaded first
        if ($hasRootIdentifierComposerJson && isset($branches[$driver->getRootIdentifier()])) {
            $branches = array($driver->getRootIdentifier() => $branches[$driver->getRootIdentifier()]) + $branches;
        }

        foreach ($branches as $branch => $identifier) {
            $branch = (string) $branch;
            $msg = 'Reading composer.json of <info>' . ($this->packageName ?: $this->url) . '</info> (<comment>' . $branch . '</comment>)';
            if ($isVeryVerbose) {
                $this->io->writeError($msg);
            } elseif ($isVerbose) {
                $this->io->overwriteError($msg, false);
            }

            if (!$parsedBranch = $this->validateBranch($branch)) {
                if ($isVeryVerbose) {
                    $this->io->writeError('<warning>Skipped branch '.$branch.', invalid name</warning>');
                }
                continue;
            }

            // make sure branch packages have a dev flag
            if (strpos($parsedBranch, 'dev-') === 0 || VersionParser::DEFAULT_BRANCH_ALIAS === $parsedBranch) {
                $version = 'dev-' . $branch;
            } else {
                $prefix = strpos($branch, 'v') === 0 ? 'v' : '';
                $version = $prefix . Preg::replace('{(\.9{7})+}', '.x', $parsedBranch);
            }

            $cachedPackage = $this->getCachedPackageVersion($version, $identifier, $isVerbose, $isVeryVerbose, $driver->getRootIdentifier() === $branch);
            if ($cachedPackage) {
                $this->addPackage($cachedPackage);

                continue;
            }
            if ($cachedPackage === false) {
                $this->emptyReferences[] = $identifier;

                continue;
            }

            try {
                $data = $driver->getComposerInformation($identifier);
                if (null === $data) {
                    if ($isVeryVerbose) {
                        $this->io->writeError('<warning>Skipped branch '.$branch.', no composer file</warning>');
                    }
                    $this->emptyReferences[] = $identifier;
                    continue;
                }

                // branches are always auto-versioned, read value from branch name
                $data['version'] = $version;
                $data['version_normalized'] = $parsedBranch;

                unset($data['default-branch']);
                if ($driver->getRootIdentifier() === $branch) {
                    $data['default-branch'] = true;
                }

                if ($isVeryVerbose) {
                    $this->io->writeError('Importing branch '.$branch.' ('.$data['version'].')');
                }

                $packageData = $this->preProcess($driver, $data, $identifier);
                $package = $this->loader->load($packageData);
                if ($this->loader instanceof ValidatingArrayLoader && $this->loader->getWarnings()) {
                    throw new InvalidPackageException($this->loader->getErrors(), $this->loader->getWarnings(), $packageData);
                }
                $this->addPackage($package);
            } catch (TransportException $e) {
                $this->versionTransportExceptions['branches'][$branch] = $e;
                if ($e->getCode() === 404) {
                    $this->emptyReferences[] = $identifier;
                }
                if ($this->shouldRethrowTransportException($e)) {
                    throw $e;
                }
                if ($isVeryVerbose) {
                    $this->io->writeError('<warning>Skipped branch '.$branch.', no composer file was found (' . $e->getCode() . ' HTTP status code)</warning>');
                }
                continue;
            } catch (\Exception $e) {
                if (!$isVeryVerbose) {
                    $this->io->writeError('');
                }
                $this->branchErrorOccurred = true;
                $this->io->writeError('<error>Skipped branch '.$branch.', '.$e->getMessage().'</error>');
                $this->io->writeError('');
                continue;
            }
        }
        $driver->cleanup();

        if (!$isVeryVerbose) {
            $this->io->overwriteError('', false);
        }

        if (!$this->getPackages()) {
            throw new InvalidRepositoryException('No valid composer.json was found in any branch or tag of '.$this->url.', could not load a package from it.');
        }
    }

    /**
     * @param VcsDriverInterface $driver
     * @param array{name?: string, dist?: array{type: string, url: string, reference: string, shasum: string}, source?: array{type: string, url: string, reference: string}} $data
     * @param string $identifier
     *
     * @return array{name: string|null, dist: array{type: string, url: string, reference: string, shasum: string}|null, source: array{type: string, url: string, reference: string}}
     */
    protected function preProcess(VcsDriverInterface $driver, array $data, string $identifier): array
    {
        // keep the name of the main identifier for all packages
        // this ensures that a package can be renamed in one place and that all old tags
        // will still be installable using that new name without requiring re-tagging
        $dataPackageName = $data['name'] ?? null;
        $data['name'] = $this->packageName ?: $dataPackageName;

        if (!isset($data['dist'])) {
            $data['dist'] = $driver->getDist($identifier);
        }
        if (!isset($data['source'])) {
            $data['source'] = $driver->getSource($identifier);
        }

        return $data;
    }

    /**
     * @param string $branch
     *
     * @return string|false
     */
    private function validateBranch(string $branch)
    {
        try {
            $normalizedBranch = $this->versionParser->normalizeBranch($branch);

            // validate that the branch name has no weird characters conflicting with constraints
            $this->versionParser->parseConstraints($normalizedBranch);

            return $normalizedBranch;
        } catch (\Exception $e) {
        }

        return false;
    }

    /**
     * @param string $version
     *
     * @return string|false
     */
    private function validateTag(string $version)
    {
        try {
            return $this->versionParser->normalize($version);
        } catch (\Exception $e) {
        }

        return false;
    }

    /**
     * @param string $version
     * @param string $identifier
     * @param bool $isVerbose
     * @param bool $isVeryVerbose
     * @param bool $isDefaultBranch
     *
     * @return \Composer\Package\CompletePackage|\Composer\Package\CompleteAliasPackage|null|false null if no cache present, false if the absence of a version was cached
     */
    private function getCachedPackageVersion(string $version, string $identifier, bool $isVerbose, bool $isVeryVerbose, bool $isDefaultBranch = false)
    {
        if (!$this->versionCache) {
            return null;
        }

        $cachedPackage = $this->versionCache->getVersionPackage($version, $identifier);
        if ($cachedPackage === false) {
            if ($isVeryVerbose) {
                $this->io->writeError('<warning>Skipped '.$version.', no composer file (cached from ref '.$identifier.')</warning>');
            }

            return false;
        }

        if ($cachedPackage) {
            $msg = 'Found cached composer.json of <info>' . ($this->packageName ?: $this->url) . '</info> (<comment>' . $version . '</comment>)';
            if ($isVeryVerbose) {
                $this->io->writeError($msg);
            } elseif ($isVerbose) {
                $this->io->overwriteError($msg, false);
            }

            unset($cachedPackage['default-branch']);
            if ($isDefaultBranch) {
                $cachedPackage['default-branch'] = true;
            }

            if ($existingPackage = $this->findPackage($cachedPackage['name'], new Constraint('=', $cachedPackage['version_normalized']))) {
                if ($isVeryVerbose) {
                    $this->io->writeError('<warning>Skipped cached version '.$version.', it conflicts with an another tag ('.$existingPackage->getPrettyVersion().') as both resolve to '.$cachedPackage['version_normalized'].' internally</warning>');
                }
                $cachedPackage = null;
            }
        }

        if ($cachedPackage) {
            return $this->loader->load($cachedPackage);
        }

        return null;
    }

    /**
     * @return bool
     */
    private function shouldRethrowTransportException(TransportException $e): bool
    {
        return in_array($e->getCode(), array(401, 403, 429), true) || $e->getCode() >= 500;
    }
}
