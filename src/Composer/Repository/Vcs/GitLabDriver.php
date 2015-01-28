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
    protected $infoCache = array();

    protected $project;
    protected $commits = array();
    protected $tags;
    protected $branches;

    /**
     * Extracts information from the repository url.
     *
     * {@inheritDoc}
     */
    public function initialize()
    {
        preg_match('#^(?:(https?|git)://([^/]+)/|git@([^:]+):)([^/]+)/(.+?)(?:\.git|/)?$#', $this->url, $match);

        $this->scheme       = $match[1];
        $this->owner        = $match[4];
        $this->repository   = $match[5];
        $this->originUrl    = !empty($match[2]) ? $match[2] : $match[3];
        $this->cache        = new Cache($this->io, $this->config->get('cache-repo-dir').'/'.$this->originUrl.'/'.$this->owner.'/'.$this->repository);

        $this->fetchProject();
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
            foreach ($this->getBranches() as $ref => $id) {
                if ($ref === $identifier) {
                    $identifier = $id;
                }
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
    public function hasComposerFile($identifier)
    {
        try {
            $this->getComposerInformation($identifier);

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
     * Fetches composer.json file from the repository through api
     *
     * @param string $identifier
     * @return array
     */
    protected function fetchComposerFile($identifier)
    {
        $resource = $this->getApiUrl() . '/repository/blobs/'.$identifier.'?filepath=composer.json';

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

            // Keep the last commit date of a reference to avoid
            // unnecessary API call when retreiving the composer file.
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
