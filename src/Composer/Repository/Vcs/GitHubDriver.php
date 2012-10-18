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
use Composer\Cache;
use Composer\IO\IOInterface;
use Composer\Util\RemoteFilesystem;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class GitHubDriver extends VcsDriver
{
    protected $cache;
    protected $owner;
    protected $repository;
    protected $tags;
    protected $branches;
    protected $rootIdentifier;
    protected $hasIssues;
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
        preg_match('#^(?:(?:https?|git)://github\.com/|git@github\.com:)([^/]+)/(.+?)(?:\.git)?$#', $this->url, $match);
        $this->owner = $match[1];
        $this->repository = $match[2];
        $this->originUrl = 'github.com';
        $this->cache = new Cache($this->io, $this->config->get('home').'/cache.github/'.$this->owner.'/'.$this->repository);

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
        $url = 'https://github.com/'.$this->owner.'/'.$this->repository.'/zipball/'.$label;

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

        if (preg_match('{[a-f0-9]{40}}i', $identifier) && $res = $this->cache->read($identifier)) {
            $this->infoCache[$identifier] = JsonFile::parseJson($res);
        }

        if (!isset($this->infoCache[$identifier])) {
            try {
                $resource = 'https://raw.github.com/'.$this->owner.'/'.$this->repository.'/'.urlencode($identifier).'/composer.json';
                $composer = $this->getContents($resource);
            } catch (TransportException $e) {
                if (404 !== $e->getCode()) {
                    throw $e;
                }

                $composer = false;
            }

            if ($composer) {
                $composer = JsonFile::parseJson($composer, $resource);

                if (!isset($composer['time'])) {
                    $resource = 'https://api.github.com/repos/'.$this->owner.'/'.$this->repository.'/commits/'.urlencode($identifier);
                    $commit = JsonFile::parseJson($this->getContents($resource), $resource);
                    $composer['time'] = $commit['commit']['committer']['date'];
                }
                if (!isset($composer['support']['source'])) {
                    $label = array_search($identifier, $this->getTags()) ?: array_search($identifier, $this->getBranches()) ?: $identifier;
                    $composer['support']['source'] = sprintf('https://github.com/%s/%s/tree/%s', $this->owner, $this->repository, $label);
                }
                if (!isset($composer['support']['issues']) && $this->hasIssues) {
                    $composer['support']['issues'] = sprintf('https://github.com/%s/%s/issues', $this->owner, $this->repository);
                }
            }

            if (preg_match('{[a-f0-9]{40}}i', $identifier)) {
                $this->cache->write($identifier, json_encode($composer));
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
            $resource = 'https://api.github.com/repos/'.$this->owner.'/'.$this->repository.'/tags';
            $tagsData = JsonFile::parseJson($this->getContents($resource), $resource);
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
            $resource = 'https://api.github.com/repos/'.$this->owner.'/'.$this->repository.'/git/refs/heads';
            $branchData = JsonFile::parseJson($this->getContents($resource), $resource);
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
        if (!preg_match('#^((?:https?|git)://github\.com/|git@github\.com:)([^/]+)/(.+?)(?:\.git)?$#', $url)) {
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
     * {@inheritDoc}
     */
    protected function getContents($url, $tryClone = false)
    {
        try {
            return parent::getContents($url);
        } catch (TransportException $e) {
            switch ($e->getCode()) {
                case 401:
                case 404:
                    if (!$this->io->isInteractive() && $tryClone) {
                        return $this->attemptCloneFallback($e);
                    }

                    $this->io->write('Your GitHub credentials are required to fetch private repository metadata (<info>'.$this->url.'</info>):');
                    $this->authorizeOauth();

                    return parent::getContents($url);

                case 403:
                    if (!$this->io->isInteractive() && $tryClone) {
                        return $this->attemptCloneFallback($e);
                    }

                    $rateLimited = false;
                    foreach ($e->getHeaders() as $header) {
                        if (preg_match('{^X-RateLimit-Remaining: *0$}i', trim($header))) {
                            $rateLimited = true;
                        }
                    }

                    if (!$this->io->hasAuthorization($this->originUrl)) {
                        if (!$this->io->isInteractive()) {
                            $this->io->write('<error>GitHub API limit exhausted. Failed to get metadata for the '.$this->url.' repository, try running in interactive mode so that you can enter your GitHub credentials to increase the API limit</error>');
                            throw $e;
                        }

                        $this->io->write('API limit exhausted. Enter your GitHub credentials to get a larger API limit (<info>'.$this->url.'</info>):');
                        $this->authorizeOauth();

                        return parent::getContents($url);
                    }

                    if ($rateLimited) {
                        $this->io->write('<error>GitHub API limit exhausted. You are already authorized so you will have to wait a while before doing more requests</error>');
                    }

                    throw $e;

                default:
                    throw $e;
            }
        }
    }

    /**
     * Fetch root identifier from GitHub
     *
     * @throws TransportException
     */
    protected function fetchRootIdentifier()
    {
        $repoDataUrl = 'https://api.github.com/repos/'.$this->owner.'/'.$this->repository;

        $repoData = JsonFile::parseJson($this->getContents($repoDataUrl, true), $repoDataUrl);
        if (null === $repoData && null !== $this->gitDriver) {
            return;
        }

        $this->isPrivate = !empty($repoData['private']);
        if (isset($repoData['default_branch'])) {
            $this->rootIdentifier = $repoData['default_branch'];
        } elseif (isset($repoData['master_branch'])) {
            $this->rootIdentifier = $repoData['master_branch'];
        } else {
            $this->rootIdentifier = 'master';
        }
        $this->hasIssues = !empty($repoData['has_issues']);
    }

    protected function attemptCloneFallback()
    {
        $this->isPrivate = true;

        try {
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
        } catch (\RuntimeException $e) {
            $this->gitDriver = null;

            $this->io->write('<error>Failed to clone the '.$this->generateSshUrl().' repository, try running in interactive mode so that you can enter your GitHub credentials</error>');
            throw $e;
        }
    }

    protected function authorizeOAuth()
    {
        $attemptCounter = 0;

        $this->io->write('The credentials will be swapped for an OAuth token stored in '.$this->config->get('home').'/config.json, your password will not be stored');
        $this->io->write('To revoke access to this token you can visit https://github.com/settings/applications');
        while ($attemptCounter++ < 5) {
            try {
                $username = $this->io->ask('Username: ');
                $password = $this->io->askAndHideAnswer('Password: ');
                $this->io->setAuthorization($this->originUrl, $username, $password);

                // build up OAuth app name
                $appName = 'Composer';
                if (0 === $this->process->execute('hostname', $output)) {
                    $appName .= ' on ' . trim($output);
                }

                $contents = JsonFile::parseJson($this->remoteFilesystem->getContents($this->originUrl, 'https://api.github.com/authorizations', false, array(
                    'http' => array(
                        'method' => 'POST',
                        'header' => "Content-Type: application/json\r\n",
                        'content' => json_encode(array(
                            'scopes' => array('repo'),
                            'note' => $appName,
                            'note_url' => 'https://getcomposer.org/',
                        )),
                    )
                )));
            } catch (TransportException $e) {
                if (401 === $e->getCode()) {
                    $this->io->write('Invalid credentials.');
                    continue;
                }

                throw $e;
            }

            $this->io->setAuthorization($this->originUrl, $contents['token'], 'x-oauth-basic');

            // store value in user config
            $githubTokens = $this->config->get('github-oauth') ?: array();
            $githubTokens[$this->originUrl] = $contents['token'];
            $this->config->getConfigSource()->addConfigSetting('github-oauth', $githubTokens);

            return;
        }

        throw new \RuntimeException("Invalid GitHub credentials 5 times in a row, aborting.");
    }
}
