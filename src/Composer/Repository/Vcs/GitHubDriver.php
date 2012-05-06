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

namespace Composer\Repository\Vcs;

use Composer\Downloader\TransportException;
use Composer\Json\JsonFile;
use Composer\IO\IOInterface;
use Composer\Util\ProcessExecutor;
use Composer\Util\RemoteFilesystem;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class GitHubDriver extends VcsDriver
{
    protected $owner;
    protected $repository;
    protected $tags;
    protected $branches;
    protected $rootIdentifier;
    protected $infoCache = array();
    protected $isPrivate = false;

    /**
     * Git Driver
     *
     * @var GitDriver
     */
    protected $gitDriver;

    /**
     * {@inheritDoc}
     */
    public function initialize()
    {
        preg_match('#^(?:https?|git)://github\.com/([^/]+)/(.+?)(?:\.git)?$#', $this->url, $match);
        $this->owner = $match[1];
        $this->repository = $match[2];

        $this->fetchRootIdentifier();
    }

    /**
     * {@inheritDoc}
     */
    public function getRootIdentifier()
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getRootIdentifier();
        }
        return $this->rootIdentifier;
    }

    /**
     * {@inheritDoc}
     */
    public function getUrl()
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getUrl();
        }
        return $this->url;
    }

    /**
     * {@inheritDoc}
     */
    public function getSource($identifier)
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getSource($identifier);
        }
        $label = array_search($identifier, $this->getTags()) ?: $identifier;
        if ($this->isPrivate) {
            // Private GitHub repositories should be accessed using the
            // SSH version of the URL.
            $url = $this->generateSshUrl();
        } else {
            $url = $this->getUrl();
        }

        return array('type' => 'git', 'url' => $url, 'reference' => $label);
    }

    /**
     * {@inheritDoc}
     */
    public function getDist($identifier)
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getDist($identifier);
        }
        $label = array_search($identifier, $this->getTags()) ?: $identifier;
        $url = $this->getScheme() . '://github.com/'.$this->owner.'/'.$this->repository.'/zipball/'.$label;

        return array('type' => 'zip', 'url' => $url, 'reference' => $label, 'shasum' => '');
    }

    /**
     * {@inheritDoc}
     */
    public function getComposerInformation($identifier)
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getComposerInformation($identifier);
        }
        if (!isset($this->infoCache[$identifier])) {
            $composer = $this->getContents($this->getScheme() . '://raw.github.com/'.$this->owner.'/'.$this->repository.'/'.$identifier.'/composer.json');
            if (!$composer) {
                return;
            }

            $composer = JsonFile::parseJson($composer);

            if (!isset($composer['time'])) {
                $commit = JsonFile::parseJson($this->getContents($this->getScheme() . '://api.github.com/repos/'.$this->owner.'/'.$this->repository.'/commits/'.$identifier));
                $composer['time'] = $commit['commit']['committer']['date'];
            }
            $this->infoCache[$identifier] = $composer;
        }

        return $this->infoCache[$identifier];
    }

    /**
     * {@inheritDoc}
     */
    public function getTags()
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getTags();
        }
        if (null === $this->tags) {
            $tagsData = JsonFile::parseJson($this->getContents($this->getScheme() . '://api.github.com/repos/'.$this->owner.'/'.$this->repository.'/tags'));
            $this->tags = array();
            foreach ($tagsData as $tag) {
                $this->tags[$tag['name']] = $tag['commit']['sha'];
            }
        }

        return $this->tags;
    }

    /**
     * {@inheritDoc}
     */
    public function getBranches()
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getBranches();
        }
        if (null === $this->branches) {
            $branchData = JsonFile::parseJson($this->getContents($this->getScheme() . '://api.github.com/repos/'.$this->owner.'/'.$this->repository.'/git/refs/heads'));
            $this->branches = array();
            foreach ($branchData as $branch) {
                $name = substr($branch['ref'], 11);
                $this->branches[$name] = $branch['object']['sha'];
            }
        }

        return $this->branches;
    }

    /**
     * {@inheritDoc}
     */
    public static function supports(IOInterface $io, $url, $deep = false)
    {
        if (!preg_match('#^(?:https?|git)://github\.com/([^/]+)/(.+?)(?:\.git)?$#', $url)) {
            return false;
        }

        if (!extension_loaded('openssl')) {
            if ($io->isVerbose()) {
                $io->write('Skipping GitHub driver for '.$url.' because the OpenSSL PHP extension is missing.');
            }
            return false;
        }

        return true;
    }

    /**
     * Generate an SSH URL
     *
     * @return string
     */
    protected function generateSshUrl()
    {
        return 'git@github.com:'.$this->owner.'/'.$this->repository.'.git';
    }

    /**
     * Fetch root identifier from GitHub
     *
     * @throws TransportException
     */
    protected function fetchRootIdentifier()
    {
        $repoDataUrl = $this->getScheme() . '://api.github.com/repos/'.$this->owner.'/'.$this->repository;
        $attemptCounter = 0;
        while (null === $this->rootIdentifier) {
            if (5 == $attemptCounter++) {
                throw new \RuntimeException("Either you have entered invalid credentials or this GitHub repository does not exists (404)");
            }
            try {
                $repoData = JsonFile::parseJson($this->getContents($repoDataUrl));
                if (isset($repoData['default_branch'])) {
                    $this->rootIdentifier = $repoData['default_branch'];
                } elseif (isset($repoData['master_branch'])) {
                    $this->rootIdentifier = $repoData['master_branch'];
                } else {
                    $this->rootIdentifier = 'master';
                }
            } catch (TransportException $e) {
                switch($e->getCode()) {
                    case 401:
                    case 404:
                        $this->isPrivate = true;
                        if (!$this->io->isInteractive()) {
                            // If this repository may be private (hard to say for sure,
                            // GitHub returns 404 for private repositories) and we
                            // cannot ask for authentication credentials (because we
                            // are not interactive) then we fallback to GitDriver.
                            $this->gitDriver = new GitDriver(
                                $this->generateSshUrl(),
                                $this->io,
                                $this->config,
                                $this->process,
                                $this->remoteFilesystem
                            );
                            $this->gitDriver->initialize();
                            return;
                        }
                        $this->io->write('Authentication required (<info>'.$this->url.'</info>):');
                        $username = $this->io->ask('Username: ');
                        $password = $this->io->askAndHideAnswer('Password: ');
                        $this->io->setAuthorization($this->url, $username, $password);
                        break;

                    default:
                        throw $e;
                        break;
                }
            }
        }
    }
}
