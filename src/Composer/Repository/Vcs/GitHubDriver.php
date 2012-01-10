<?php

namespace Composer\Repository\Vcs;

use Composer\Json\JsonFile;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class GitHubDriver extends VcsDriver implements VcsDriverInterface
{
    protected $owner;
    protected $repository;
    protected $tags;
    protected $branches;
    protected $rootIdentifier;
    protected $infoCache = array();

    public function __construct($url)
    {
        preg_match('#^(?:https?|http|git)://github\.com/([^/]+)/(.+?)(?:\.git)?$#', $url, $match);
        $this->owner = $match[1];
        $this->repository = $match[2];

        parent::__construct($url);
    }

    /**
     * {@inheritDoc}
     */
    public function initialize()
    {
    }

    /**
     * {@inheritDoc}
     */
    public function getRootIdentifier()
    {
        if (null === $this->rootIdentifier) {
            $repoData = json_decode(file_get_contents($this->getHttpSupport() . '://api.github.com/repos/'.$this->owner.'/'.$this->repository), true);
            $this->rootIdentifier = $repoData['master_branch'] ?: 'master';
        }

        return $this->rootIdentifier;
    }

    /**
     * {@inheritDoc}
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * {@inheritDoc}
     */
    public function getSource($identifier)
    {
        $label = array_search($identifier, $this->getTags()) ?: $identifier;

        return array('type' => 'git', 'url' => $this->getUrl(), 'reference' => $label);
    }

    /**
     * {@inheritDoc}
     */
    public function getDist($identifier)
    {
        $label = array_search($identifier, $this->getTags()) ?: $identifier;
        $url = $this->getHttpSupport() . '://github.com/'.$this->owner.'/'.$this->repository.'/zipball/'.$label;

        return array('type' => 'zip', 'url' => $url, 'reference' => $label, 'shasum' => '');
    }

    /**
     * {@inheritDoc}
     */
    public function getComposerInformation($identifier)
    {
        if (!isset($this->infoCache[$identifier])) {
            $composer = @file_get_contents($this->getHttpSupport() . '://raw.github.com/'.$this->owner.'/'.$this->repository.'/'.$identifier.'/composer.json');
            if (!$composer) {
                throw new \UnexpectedValueException('Failed to retrieve composer information for identifier '.$identifier.' in '.$this->getUrl());
            }

            $composer = JsonFile::parseJson($composer);

            if (!isset($composer['time'])) {
                $commit = json_decode(file_get_contents($this->getHttpSupport() . '://api.github.com/repos/'.$this->owner.'/'.$this->repository.'/commits/'.$identifier), true);
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
        if (null === $this->tags) {
            $tagsData = json_decode(file_get_contents($this->getHttpSupport() . '://api.github.com/repos/'.$this->owner.'/'.$this->repository.'/tags'), true);
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
        if (null === $this->branches) {
            $branchData = json_decode(file_get_contents($this->getHttpSupport() . '://api.github.com/repos/'.$this->owner.'/'.$this->repository.'/branches'), true);
            $this->branches = array();
            foreach ($branchData as $branch) {
                $this->branches[$branch['name']] = $branch['commit']['sha'];
            }
        }

        return $this->branches;
    }

    /**
     * {@inheritDoc}
     */
    public function hasComposerFile($identifier)
    {
        try {
            $this->getComposerInformation($identifier);
            return true;
        } catch (\Exception $e) {
        }

        return false;
    }

    /**
     * {@inheritDoc}
     */
    public static function supports($url, $deep = false)
    {
        return extension_loaded('openssl') && preg_match('#^(?:https?|git)://github\.com/([^/]+)/(.+?)(?:\.git)?$#', $url, $match);
    }
}
