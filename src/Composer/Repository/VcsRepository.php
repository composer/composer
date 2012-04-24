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
use Composer\Package\PackageInterface;
use Composer\Package\AliasPackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\IO\IOInterface;
use Composer\Config;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class VcsRepository extends ArrayRepository
{
    protected $url;
    protected $packageName;
    protected $verbose;
    protected $io;
    protected $config;
    protected $versionParser;
    protected $type;

    public function __construct(array $repoConfig, IOInterface $io, Config $config = null, array $drivers = null)
    {
        $this->drivers = $drivers ?: array(
            'github'        => 'Composer\Repository\Vcs\GitHubDriver',
            'git-bitbucket' => 'Composer\Repository\Vcs\GitBitbucketDriver',
            'git'           => 'Composer\Repository\Vcs\GitDriver',
            'svn'           => 'Composer\Repository\Vcs\SvnDriver',
            'hg-bitbucket'  => 'Composer\Repository\Vcs\HgBitbucketDriver',
            'hg'            => 'Composer\Repository\Vcs\HgDriver',
        );

        $this->url = $repoConfig['url'];
        $this->io = $io;
        $this->type = isset($repoConfig['type']) ? $repoConfig['type'] : 'vcs';
        $this->verbose = $io->isVerbose();
        $this->config = $config;
    }

    public function getDriver()
    {
        if (isset($this->drivers[$this->type])) {
            $class = $this->drivers[$this->type];
            $driver = new $class($this->url, $this->io, $this->config);
            $driver->initialize();
            return $driver;
        }

        foreach ($this->drivers as $driver) {
            if ($driver::supports($this->io, $this->url)) {
                $driver = new $driver($this->url, $this->io, $this->config);
                $driver->initialize();
                return $driver;
            }
        }

        foreach ($this->drivers as $driver) {
            if ($driver::supports($this->io, $this->url, true)) {
                $driver = new $driver($this->url, $this->io, $this->config);
                $driver->initialize();
                return $driver;
            }
        }
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
        $loader = new ArrayLoader();

        try {
            if ($driver->hasComposerFile($driver->getRootIdentifier())) {
                $data = $driver->getComposerInformation($driver->getRootIdentifier());
                $this->packageName = !empty($data['name']) ? $data['name'] : null;
            }
        } catch (\Exception $e) {
            if ($verbose) {
                $this->io->write('Skipped parsing '.$driver->getRootIdentifier().', '.$e->getMessage());
            }
        }

        foreach ($driver->getTags() as $tag => $identifier) {
            $msg = 'Get composer info for <info>' . ($this->packageName ?: $this->url) . '</info> (<comment>' . $tag . '</comment>)';
            if ($verbose) {
                $this->io->write($msg);
            } else {
                $this->io->overwrite($msg, false);
            }

            if (!$parsedTag = $this->validateTag($tag)) {
                if ($verbose) {
                    $this->io->write('Skipped tag '.$tag.', invalid tag name');
                }
                continue;
            }

            try {
                if (!$data = $driver->getComposerInformation($identifier)) {
                    if ($verbose) {
                        $this->io->write('Skipped tag '.$tag.', no composer file');
                    }
                    continue;
                }
            } catch (\Exception $e) {
                if ($verbose) {
                    $this->io->write('Skipped tag '.$tag.', '.($e instanceof TransportException ? 'no composer file was found' : $e->getMessage()));
                }
                continue;
            }

            // manually versioned package
            if (isset($data['version'])) {
                $data['version_normalized'] = $this->versionParser->normalize($data['version']);
            } else {
                // auto-versionned package, read value from tag
                $data['version'] = $tag;
                $data['version_normalized'] = $parsedTag;
            }

            // make sure tag packages have no -dev flag
            $data['version'] = preg_replace('{[.-]?dev$}i', '', $data['version']);
            $data['version_normalized'] = preg_replace('{(^dev-|[.-]?dev$)}i', '', $data['version_normalized']);

            // broken package, version doesn't match tag
            if ($data['version_normalized'] !== $parsedTag) {
                if ($verbose) {
                    $this->io->write('Skipped tag '.$tag.', tag ('.$parsedTag.') does not match version ('.$data['version_normalized'].') in composer.json');
                }
                continue;
            }

            if ($verbose) {
                $this->io->write('Importing tag '.$tag.' ('.$data['version_normalized'].')');
            }

            $this->addPackage($loader->load($this->preProcess($driver, $data, $identifier)));
        }

        $this->io->overwrite('', false);

        foreach ($driver->getBranches() as $branch => $identifier) {
            $msg = 'Get composer info for <info>' . ($this->packageName ?: $this->url) . '</info> (<comment>' . $branch . '</comment>)';
            if ($verbose) {
                $this->io->write($msg);
            } else {
                $this->io->overwrite($msg, false);
            }

            if (!$parsedBranch = $this->validateBranch($branch)) {
                if ($verbose) {
                    $this->io->write('Skipped branch '.$branch.', invalid name');
                }
                continue;
            }

            try {
                if (!$data = $driver->getComposerInformation($identifier)) {
                    if ($verbose) {
                        $this->io->write('Skipped branch '.$branch.', no composer file');
                    }
                    continue;
                }
            } catch (TransportException $e) {
                if ($verbose) {
                    $this->io->write('Skipped branch '.$branch.', no composer file was found');
                }
                continue;
            } catch (\Exception $e) {
                $this->io->write('Skipped branch '.$branch.', '.$e->getMessage());
                continue;
            }

            // branches are always auto-versionned, read value from branch name
            $data['version'] = $branch;
            $data['version_normalized'] = $parsedBranch;

            // make sure branch packages have a dev flag
            if ('dev-' === substr($parsedBranch, 0, 4) || '9999999-dev' === $parsedBranch) {
                $data['version'] = 'dev-' . $data['version'];
            } else {
                $data['version'] = preg_replace('{(\.9{7})+}', '.x', $parsedBranch);
            }

            if ($verbose) {
                $this->io->write('Importing branch '.$branch.' ('.$data['version'].')');
            }

            $this->addPackage($loader->load($this->preProcess($driver, $data, $identifier)));
        }

        $this->io->overwrite('', false);
    }

    private function preProcess(VcsDriverInterface $driver, array $data, $identifier)
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
