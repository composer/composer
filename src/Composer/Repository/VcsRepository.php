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

namespace Composer\Repository;

use Composer\Downloader\TransportException;
use Composer\Repository\Vcs\VcsDriverInterface;
use Composer\Package\Version\VersionParser;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Loader\ValidatingArrayLoader;
use Composer\Package\Loader\InvalidPackageException;
use Composer\Package\Loader\LoaderInterface;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Config;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class VcsRepository extends ArrayRepository implements ConfigurableRepositoryInterface
{
    protected $url;
    protected $packageName;
    protected $verbose;
    protected $io;
    protected $config;
    protected $versionParser;
    protected $type;
    protected $loader;
    protected $repoConfig;
    protected $branchErrorOccurred = false;
    private $drivers;
    /** @var VcsDriverInterface */
    private $driver;

    public function __construct(array $repoConfig, IOInterface $io, Config $config, EventDispatcher $dispatcher = null, array $drivers = null)
    {
        parent::__construct();
        $this->drivers = $drivers ?: array(
            'github'        => 'Composer\Repository\Vcs\GitHubDriver',
            'gitlab'        => 'Composer\Repository\Vcs\GitLabDriver',
            'git-bitbucket' => 'Composer\Repository\Vcs\GitBitbucketDriver',
            'git'           => 'Composer\Repository\Vcs\GitDriver',
            'hg-bitbucket'  => 'Composer\Repository\Vcs\HgBitbucketDriver',
            'hg'            => 'Composer\Repository\Vcs\HgDriver',
            'perforce'      => 'Composer\Repository\Vcs\PerforceDriver',
            'fossil'        => 'Composer\Repository\Vcs\FossilDriver',
            // svn must be last because identifying a subversion server for sure is practically impossible
            'svn'           => 'Composer\Repository\Vcs\SvnDriver',
        );

        $this->url = $repoConfig['url'];
        $this->io = $io;
        $this->type = isset($repoConfig['type']) ? $repoConfig['type'] : 'vcs';
        $this->verbose = $io->isVeryVerbose();
        $this->config = $config;
        $this->repoConfig = $repoConfig;
    }

    public function getRepoConfig()
    {
        return $this->repoConfig;
    }

    public function setLoader(LoaderInterface $loader)
    {
        $this->loader = $loader;
    }

    public function getDriver()
    {
        if ($this->driver) {
            return $this->driver;
        }

        if (isset($this->drivers[$this->type])) {
            $class = $this->drivers[$this->type];
            $this->driver = new $class($this->repoConfig, $this->io, $this->config);
            $this->driver->initialize();

            return $this->driver;
        }

        foreach ($this->drivers as $driver) {
            if ($driver::supports($this->io, $this->config, $this->url)) {
                $this->driver = new $driver($this->repoConfig, $this->io, $this->config);
                $this->driver->initialize();

                return $this->driver;
            }
        }

        foreach ($this->drivers as $driver) {
            if ($driver::supports($this->io, $this->config, $this->url, true)) {
                $this->driver = new $driver($this->repoConfig, $this->io, $this->config);
                $this->driver->initialize();

                return $this->driver;
            }
        }
    }

    public function hadInvalidBranches()
    {
        return $this->branchErrorOccurred;
    }

    protected function initialize()
    {
        parent::initialize();

        $verbose = $this->verbose;

        $driver = $this->getDriver();
        if (!$driver) {
            throw new \InvalidArgumentException('No driver found to handle VCS repository '.$this->url);
        }

        $this->versionParser = new VersionParser;
        if (!$this->loader) {
            $this->loader = new ArrayLoader($this->versionParser);
        }

        try {
            if ($driver->hasComposerFile($driver->getRootIdentifier())) {
                $data = $driver->getComposerInformation($driver->getRootIdentifier());
                $this->packageName = !empty($data['name']) ? $data['name'] : null;
            }
        } catch (\Exception $e) {
            if ($verbose) {
                $this->io->writeError('<error>Skipped parsing '.$driver->getRootIdentifier().', '.$e->getMessage().'</error>');
            }
        }

        foreach ($driver->getTags() as $tag => $identifier) {
            $msg = 'Reading composer.json of <info>' . ($this->packageName ?: $this->url) . '</info> (<comment>' . $tag . '</comment>)';
            if ($verbose) {
                $this->io->writeError($msg);
            } else {
                $this->io->overwriteError($msg, false);
            }

            // strip the release- prefix from tags if present
            $tag = str_replace('release-', '', $tag);

            if (!$parsedTag = $this->validateTag($tag)) {
                if ($verbose) {
                    $this->io->writeError('<warning>Skipped tag '.$tag.', invalid tag name</warning>');
                }
                continue;
            }

            try {
                if (!$data = $driver->getComposerInformation($identifier)) {
                    if ($verbose) {
                        $this->io->writeError('<warning>Skipped tag '.$tag.', no composer file</warning>');
                    }
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
                $data['version'] = preg_replace('{[.-]?dev$}i', '', $data['version']);
                $data['version_normalized'] = preg_replace('{(^dev-|[.-]?dev$)}i', '', $data['version_normalized']);

                // broken package, version doesn't match tag
                if ($data['version_normalized'] !== $parsedTag) {
                    if ($verbose) {
                        $this->io->writeError('<warning>Skipped tag '.$tag.', tag ('.$parsedTag.') does not match version ('.$data['version_normalized'].') in composer.json</warning>');
                    }
                    continue;
                }

                if ($verbose) {
                    $this->io->writeError('Importing tag '.$tag.' ('.$data['version_normalized'].')');
                }

                $this->addPackage($this->loader->load($this->preProcess($driver, $data, $identifier)));
            } catch (\Exception $e) {
                if ($verbose) {
                    $this->io->writeError('<warning>Skipped tag '.$tag.', '.($e instanceof TransportException ? 'no composer file was found' : $e->getMessage()).'</warning>');
                }
                continue;
            }
        }

        if (!$verbose) {
            $this->io->overwriteError('', false);
        }

        foreach ($driver->getBranches() as $branch => $identifier) {
            $msg = 'Reading composer.json of <info>' . ($this->packageName ?: $this->url) . '</info> (<comment>' . $branch . '</comment>)';
            if ($verbose) {
                $this->io->writeError($msg);
            } else {
                $this->io->overwriteError($msg, false);
            }

            if (!$parsedBranch = $this->validateBranch($branch)) {
                if ($verbose) {
                    $this->io->writeError('<warning>Skipped branch '.$branch.', invalid name</warning>');
                }
                continue;
            }

            try {
                if (!$data = $driver->getComposerInformation($identifier)) {
                    if ($verbose) {
                        $this->io->writeError('<warning>Skipped branch '.$branch.', no composer file</warning>');
                    }
                    continue;
                }

                // branches are always auto-versioned, read value from branch name
                $data['version'] = $branch;
                $data['version_normalized'] = $parsedBranch;

                // make sure branch packages have a dev flag
                if ('dev-' === substr($parsedBranch, 0, 4) || '9999999-dev' === $parsedBranch) {
                    $data['version'] = 'dev-' . $data['version'];
                } else {
                    $prefix = substr($branch, 0, 1) === 'v' ? 'v' : '';
                    $data['version'] = $prefix . preg_replace('{(\.9{7})+}', '.x', $parsedBranch);
                }

                if ($verbose) {
                    $this->io->writeError('Importing branch '.$branch.' ('.$data['version'].')');
                }

                $packageData = $this->preProcess($driver, $data, $identifier);
                $package = $this->loader->load($packageData);
                if ($this->loader instanceof ValidatingArrayLoader && $this->loader->getWarnings()) {
                    throw new InvalidPackageException($this->loader->getErrors(), $this->loader->getWarnings(), $packageData);
                }
                $this->addPackage($package);
            } catch (TransportException $e) {
                if ($verbose) {
                    $this->io->writeError('<warning>Skipped branch '.$branch.', no composer file was found</warning>');
                }
                continue;
            } catch (\Exception $e) {
                if (!$verbose) {
                    $this->io->writeError('');
                }
                $this->branchErrorOccurred = true;
                $this->io->writeError('<error>Skipped branch '.$branch.', '.$e->getMessage().'</error>');
                $this->io->writeError('');
                continue;
            }
        }
        $driver->cleanup();

        if (!$verbose) {
            $this->io->overwriteError('', false);
        }

        if (!$this->getPackages()) {
            throw new InvalidRepositoryException('No valid composer.json was found in any branch or tag of '.$this->url.', could not load a package from it.');
        }
    }

    protected function preProcess(VcsDriverInterface $driver, array $data, $identifier)
    {
        // keep the name of the main identifier for all packages
        $data['name'] = $this->packageName ?: $data['name'];

        if (!isset($data['dist'])) {
            $data['dist'] = $driver->getDist($identifier);
        }
        if (!isset($data['source'])) {
            $data['source'] = $driver->getSource($identifier);
        }

        return $data;
    }

    private function validateBranch($branch)
    {
        try {
            return $this->versionParser->normalizeBranch($branch);
        } catch (\Exception $e) {
        }

        return false;
    }

    private function validateTag($version)
    {
        try {
            return $this->versionParser->normalize($version);
        } catch (\Exception $e) {
        }

        return false;
    }
}
