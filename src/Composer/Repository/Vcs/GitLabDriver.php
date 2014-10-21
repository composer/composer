<?php

namespace Composer\Repository\Vcs;

use Composer\Config;
use Composer\Cache;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Downloader\TransportException;

/**
 * Simplistic driver for GitLab currently only supports the api, not local checkouts.
 */
class GitLabDriver extends VcsDriver
{
    protected $owner;
    protected $repository;
    protected $originUrl;
    protected $cache;
    protected $rootIdentifier;
    protected $infoCache = array();

    /**
     * Extracts information from the repository url.
     *
     * {@inheritDoc}
     */
    public function initialize()
    {
        preg_match('#^(?:(?:https?|git)://([^/]+)/|git@([^:]+):)([^/]+)/(.+?)(?:\.git|/)?$#', $this->url, $match);

        $this->owner        = $match[3];
        $this->repository   = $match[4];
        $this->originUrl    = !empty($match[1]) ? $match[1] : $match[2];
        $this->cache        = new Cache($this->io, $this->config->get('cache-repo-dir').'/'.$this->originUrl.'/'.$this->owner.'/'.$this->repository);

        $this->fetchRootIdentifier();
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
        if (isset($this->infoCache[$identifier])) {
            return $this->infoCache[$identifier];
        }

        if (preg_match('{[a-f0-9]{40}}i', $identifier) && $res = $this->cache->read($identifier)) {
            return $this->infoCache[$identifier] = JsonFile::parseJson($res, $res);
        }

        $composer = $this->fetchComposerFile($identifier);

        if (empty($composer['content']) || $composer['encoding'] !== 'base64' || !($composer = base64_decode($composer['content']))) {
            throw new \RuntimeException('Could not retrieve composer.json from GitLab#'.$identifier);
        }

        $composer = JsonFile::parseJson($composer);

        if (!isset($composer['time'])) {
            $resource = $this->getApiUrl().'/repository/commits/'.urlencode($identifier);
            $commit = JsonFile::parseJson($this->getContents($resource), $resource);

            $composer['time'] = $commit['committed_date'];
        }

        if (preg_match('{[a-f0-9]{40}}i', $identifier)) {
            $this->cache->write($identifier, json_encode($composer));
        }

        $this->infoCache[$identifier] = $composer;
    }

    /**
     * {@inheritDoc}
     */
    public function hasComposerFile($identifier)
    {
        try {
            $this->fetchComposerFile($identifier);

            return true;
        } catch (TransportException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }

        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function getRepositoryUrl()
    {
        return 'https://'.$this->originUrl.'/'.$this->owner.'/'.$this->repository;
    }

    /**
     * {@inheritDoc}
     */
    public function getUrl()
    {
        return $this->getRepositoryUrl() . '.git';
    }

    /**
     * {@inheritDoc}
     */
    public function getDist($identifier)
    {
        $url = $this->getApiUrl().'/repository/archive?sha='.$identifier;

        return array('type' => 'zip', 'url' => $url, 'reference' => $identifier, 'shasum' => '');
    }

    /**
     * {@inheritDoc}
     */
    public function getSource($identifier)
    {
        return array('type' => 'git', 'url' => $this->getUrl(), 'reference' => $identifier);
    }

    /**
     * {@inheritDoc}
     */
    public function getRootIdentifier()
    {
        return $this->rootIdentifier;
    }

    /**
     * {@inheritDoc}
     */
    public function getBranches()
    {
        return $this->getReferences('branches');
    }

    /**
     * {@inheritDoc}
     */
    public function getTags()
    {
        return $this->getReferences('tags');
    }

    /**
     * Fetches composer.json file from the repository through api
     *
     * @param string $identifier
     * @return array
     */
    protected function fetchComposerFile($identifier)
    {
        $resource = $this->getApiUrl() . '/repository/files?file_path=composer.json&ref='.$identifier;

        return JsonFile::parseJson($this->getContents($resource), $resource);
    }

    /**
     * Root url
     *
     * {@inheritDoc}
     */
    protected function getApiUrl()
    {
        // this needs to be https, but our install is running http
        return 'http://'.$this->originUrl.'/api/v3/projects/'.$this->owner.'%2F'.$this->repository;
    }

    /**
     * @param string $type
     * @return string[] where keys are named references like tags or branches and the value a sha
     */
    protected function getReferences($type)
    {
        $resource = $this->getApiUrl().'/repository/'.$type;

        $data = JsonFile::parseJson($this->getContents($resource), $resource);

        $references = array();

        foreach ($data as $datum) {
            $references[$datum['name']] = $datum['commit']['id'];
        }

        return $references;
    }

    protected function fetchRootIdentifier()
    {
        // we need to fetch the default branch from the api
        $resource = $this->getApiUrl();

        $project = JsonFile::parseJson($this->getContents($resource), $resource);

        $this->rootIdentifier = $project['default_branch'];
    }

    /**
     * Uses the config `gitlab-domains` to see if the driver supports the url for the 
     * repository given.
     *
     * {@inheritDoc}
     */
    public static function supports(IOInterface $io, Config $config, $url, $deep = false)
    {
        if (!preg_match('#^((?:https?|git)://([^/]+)/|git@([^:]+):)([^/]+)/(.+?)(?:\.git|/)?$#', $url, $matches)) {
            return false;
        }

        $originUrl = empty($matches[2]) ? $matches[3] : $matches[2];

        if (!in_array($originUrl, (array) $config->get('gitlab-domains'))) {
            return false;
        }

        if (!extension_loaded('openssl')) {
            if ($io->isVerbose()) {
                $io->write('Skipping GitLab driver for '.$url.' because the OpenSSL PHP extension is missing.');
            }

            return false;
        }

        return true;
    }
}
