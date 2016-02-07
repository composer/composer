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

use Composer\Config;
use Composer\Cache;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Downloader\TransportException;
use Composer\Util\RemoteFilesystem;
use Composer\Util\GitLab;

/**
 * Driver for GitLab API, use the Git driver for local checkouts.
 *
 * @author Henrik Bjørnskov <henrik@bjrnskov.dk>
 * @author Jérôme Tamarelle <jerome@tamarelle.net>
 */
class GitLabDriver extends VcsDriver
{
    private $scheme;
    private $owner;
    private $repository;

    private $cache;
    private $infoCache = array();

    /**
     * @var array Project data returned by GitLab API
     */
    private $project;

    /**
     * @var array Keeps commits returned by GitLab API
     */
    private $commits = array();

    /**
     * @var array List of tag => reference
     */
    private $tags;

    /**
     * @var array List of branch => reference
     */
    private $branches;

    /**
     * Git Driver
     *
     * @var GitDriver
     */
    protected $gitDriver;

    const URL_REGEX = '#^(?:(?P<scheme>https?)://(?P<domain>.+?)/|git@(?P<domain2>[^:]+):)(?P<owner>[^/]+)/(?P<repo>[^/]+?)(?:\.git|/)?$#';

    /**
     * Extracts information from the repository url.
     * SSH urls uses https by default.
     *
     * {@inheritDoc}
     */
    public function initialize()
    {
        if (!preg_match(self::URL_REGEX, $this->url, $match)) {
            throw new \InvalidArgumentException('The URL provided is invalid. It must be the HTTP URL of a GitLab project.');
        }

        $this->scheme = !empty($match['scheme']) ? $match['scheme'] : 'https';
        $this->originUrl = !empty($match['domain']) ? $match['domain'] : $match['domain2'];
        $this->owner = $match['owner'];
        $this->repository = preg_replace('#(\.git)$#', '', $match['repo']);

        $this->cache = new Cache($this->io, $this->config->get('cache-repo-dir').'/'.$this->originUrl.'/'.$this->owner.'/'.$this->repository);

        $this->fetchProject();
    }

    /**
     * Updates the RemoteFilesystem instance.
     * Mainly useful for tests.
     *
     * @internal
     */
    public function setRemoteFilesystem(RemoteFilesystem $remoteFilesystem)
    {
        $this->remoteFilesystem = $remoteFilesystem;
    }

    /**
     * Fetches the composer.json file from the project by a identifier.
     *
     * if specific keys arent present it will try and infer them by default values.
     *
     * {@inheritDoc}
     */
    public function getComposerInformation($identifier)
    {
        // Convert the root identifier to a cachable commit id
        if (!preg_match('{[a-f0-9]{40}}i', $identifier)) {
            $branches = $this->getBranches();
            if (isset($branches[$identifier])) {
                $identifier = $branches[$identifier];
            }
        }

        if (isset($this->infoCache[$identifier])) {
            return $this->infoCache[$identifier];
        }

        if (preg_match('{[a-f0-9]{40}}i', $identifier) && $res = $this->cache->read($identifier)) {
            return $this->infoCache[$identifier] = JsonFile::parseJson($res, $res);
        }

        try {
            $composer = $this->fetchComposerFile($identifier);
        } catch (TransportException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
            $composer = false;
        }

        if ($composer && !isset($composer['time']) && isset($this->commits[$identifier])) {
            $composer['time'] = $this->commits[$identifier]['committed_date'];
        }

        if (preg_match('{[a-f0-9]{40}}i', $identifier)) {
            $this->cache->write($identifier, json_encode($composer));
        }

        return $this->infoCache[$identifier] = $composer;
    }

    /**
     * {@inheritDoc}
     */
    public function getRepositoryUrl()
    {
        return $this->project['ssh_url_to_repo'];
    }

    /**
     * {@inheritDoc}
     */
    public function getUrl()
    {
        return $this->project['web_url'];
    }

    /**
     * {@inheritDoc}
     */
    public function getDist($identifier)
    {
        $url = $this->getApiUrl().'/repository/archive.zip?sha='.$identifier;

        return array('type' => 'zip', 'url' => $url, 'reference' => $identifier, 'shasum' => '');
    }

    /**
     * {@inheritDoc}
     */
    public function getSource($identifier)
    {
        return array('type' => 'git', 'url' => $this->getRepositoryUrl(), 'reference' => $identifier);
    }

    /**
     * {@inheritDoc}
     */
    public function getRootIdentifier()
    {
        return $this->project['default_branch'];
    }

    /**
     * {@inheritDoc}
     */
    public function getBranches()
    {
        if (!$this->branches) {
            $this->branches = $this->getReferences('branches');
        }

        return $this->branches;
    }

    /**
     * {@inheritDoc}
     */
    public function getTags()
    {
        if (!$this->tags) {
            $this->tags = $this->getReferences('tags');
        }

        return $this->tags;
    }

    /**
     * Fetches composer.json file from the repository through api.
     *
     * @param string $identifier
     *
     * @return array
     */
    protected function fetchComposerFile($identifier)
    {
        $resource = $this->getApiUrl().'/repository/blobs/'.$identifier.'?filepath=composer.json';

        return JsonFile::parseJson($this->getContents($resource), $resource);
    }

    /**
     * @return string Base URL for GitLab API v3
     */
    public function getApiUrl()
    {
        return $this->scheme.'://'.$this->originUrl.'/api/v3/projects/'.$this->owner.'%2F'.$this->repository;
    }

    /**
     * @param string $type
     *
     * @return string[] where keys are named references like tags or branches and the value a sha
     */
    protected function getReferences($type)
    {
        $resource = $this->getApiUrl().'/repository/'.$type;

        $data = JsonFile::parseJson($this->getContents($resource), $resource);

        $references = array();

        foreach ($data as $datum) {
            $references[$datum['name']] = $datum['commit']['id'];

            // Keep the last commit date of a reference to avoid
            // unnecessary API call when retrieving the composer file.
            $this->commits[$datum['commit']['id']] = $datum['commit'];
        }

        return $references;
    }

    protected function fetchProject()
    {
        // we need to fetch the default branch from the api
        $resource = $this->getApiUrl();
        $this->project = JsonFile::parseJson($this->getContents($resource, true), $resource);
    }

    protected function attemptCloneFallback()
    {
        try {
            // If this repository may be private and we
            // cannot ask for authentication credentials (because we
            // are not interactive) then we fallback to GitDriver.
            $this->setupGitDriver($this->generateSshUrl());

            return;
        } catch (\RuntimeException $e) {
            $this->gitDriver = null;

            $this->io->writeError('<error>Failed to clone the '.$this->generateSshUrl().' repository, try running in interactive mode so that you can enter your credentials</error>');
            throw $e;
        }
    }

    /**
     * Generate an SSH URL
     *
     * @return string
     */
    protected function generateSshUrl()
    {
        return 'git@' . $this->originUrl . ':'.$this->owner.'/'.$this->repository.'.git';
    }

    protected function setupGitDriver($url)
    {
        $this->gitDriver = new GitDriver(
            array('url' => $url),
            $this->io,
            $this->config,
            $this->process,
            $this->remoteFilesystem
        );
        $this->gitDriver->initialize();
    }

    /**
     * {@inheritDoc}
     */
    protected function getContents($url, $fetchingRepoData = false)
    {
        try {
            return parent::getContents($url);
        } catch (TransportException $e) {
            $gitLabUtil = new GitLab($this->io, $this->config, $this->process, $this->remoteFilesystem);

            switch ($e->getCode()) {
                case 401:
                case 404:
                    // try to authorize only if we are fetching the main /repos/foo/bar data, otherwise it must be a real 404
                    if (!$fetchingRepoData) {
                        throw $e;
                    }

                    if ($gitLabUtil->authorizeOAuth($this->originUrl)) {
                        return parent::getContents($url);
                    }

                    if (!$this->io->isInteractive()) {
                        return $this->attemptCloneFallback();
                    }
                    $this->io->writeError('<warning>Failed to download ' . $this->owner . '/' . $this->repository . ':' . $e->getMessage() . '</warning>');
                    $gitLabUtil->authorizeOAuthInteractively($this->originUrl, 'Your credentials are required to fetch private repository metadata (<info>'.$this->url.'</info>)');

                    return parent::getContents($url);

                case 403:
                    if (!$this->io->hasAuthentication($this->originUrl) && $gitLabUtil->authorizeOAuth($this->originUrl)) {
                        return parent::getContents($url);
                    }

                    if (!$this->io->isInteractive() && $fetchingRepoData) {
                        return $this->attemptCloneFallback();
                    }

                    throw $e;

                default:
                    throw $e;
            }
        }
    }

    /**
     * Uses the config `gitlab-domains` to see if the driver supports the url for the
     * repository given.
     *
     * {@inheritDoc}
     */
    public static function supports(IOInterface $io, Config $config, $url, $deep = false)
    {
        if (!preg_match(self::URL_REGEX, $url, $match)) {
            return false;
        }

        $scheme = !empty($match['scheme']) ? $match['scheme'] : 'https';
        $originUrl = !empty($match['domain']) ? $match['domain'] : $match['domain2'];

        if (!in_array($originUrl, (array) $config->get('gitlab-domains'))) {
            return false;
        }

        if ('https' === $scheme && !extension_loaded('openssl')) {
            $io->writeError('Skipping GitLab driver for '.$url.' because the OpenSSL PHP extension is missing.', true, IOInterface::VERBOSE);

            return false;
        }

        return true;
    }
}
