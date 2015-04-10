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
     * Extracts information from the repository url.
     * SSH urls are not supported in order to know the HTTP sheme to use.
     *
     * {@inheritDoc}
     */
    public function initialize()
    {
        if (!preg_match('#^(https?)://([^/]+)/([^/]+)/([^/]+)(?:\.git|/)?$#', $this->url, $match)) {
            throw new \InvalidArgumentException('The URL provided is invalid. It must be the HTTP URL of a GitLab project.');
        }

        $this->scheme = $match[1];
        $this->originUrl = $match[2];
        $this->owner = $match[3];
        $this->repository = preg_replace('#(\.git)$#', '', $match[4]);
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

        $this->project = JsonFile::parseJson($this->getContents($resource), $resource);
    }

    /**
     * Uses the config `gitlab-domains` to see if the driver supports the url for the
     * repository given.
     *
     * {@inheritDoc}
     */
    public static function supports(IOInterface $io, Config $config, $url, $deep = false)
    {
        if (!preg_match('#^(https?)://([^/]+)/([^/]+)/([^/]+)(?:\.git|/)?$#', $url, $match)) {
            return false;
        }

        $scheme = $match[1];
        $originUrl = $match[2];

        if (!in_array($originUrl, (array) $config->get('gitlab-domains'))) {
            return false;
        }

        if ('https' === $scheme && !extension_loaded('openssl')) {
            if ($io->isVerbose()) {
                $io->write('Skipping GitLab driver for '.$url.' because the OpenSSL PHP extension is missing.');
            }

            return false;
        }

        return true;
    }
}
