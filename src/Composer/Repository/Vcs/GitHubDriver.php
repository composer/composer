<?php

namespace Composer\Repository\Vcs;

use Composer\Json\JsonFile;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class GitHubDriver implements VcsDriverInterface
{
    protected $owner;
    protected $repository;
    protected $tags;
    protected $branches;
    protected $infoCache = array();

    public function __construct($url)
    {
        preg_match('#^(?:https?|git)://github\.com/([^/]+)/(.+?)(?:\.git)?$#', $url, $match);
        $this->owner = $match[1];
        $this->repository = $match[2];
    }

    /**
     * {@inheritDoc}
     */
    public function initialize()
    {
        $this->getTags();
        $this->getBranches();
    }

    /**
     * {@inheritDoc}
     */
    public function getRootIdentifier()
    {
        return 'master';
    }

    /**
     * {@inheritDoc}
     */
    public function getUrl()
    {
        return 'http://github.com/'.$this->owner.'/'.$this->repository.'.git';
    }

    /**
     * {@inheritDoc}
     */
    public function getSource($identifier)
    {
        $label = array_search($identifier, (array) $this->tags) ?: $identifier;

        return array('type' => 'git', 'url' => $this->getUrl(), 'reference' => $label);
    }

    /**
     * {@inheritDoc}
     */
    public function getDist($identifier)
    {
        $label = array_search($identifier, (array) $this->tags) ?: $identifier;
        $url = 'http://github.com/'.$this->owner.'/'.$this->repository.'/zipball/'.$label;

        return array('type' => 'zip', 'url' => $url, 'reference' => $label, 'shasum' => '');
    }

    /**
     * {@inheritDoc}
     */
    public function getComposerInformation($identifier)
    {
        if (!isset($this->infoCache[$identifier])) {
            $composer = @file_get_contents('https://raw.github.com/'.$this->owner.'/'.$this->repository.'/'.$identifier.'/composer.json');
            if (!$composer) {
                throw new \UnexpectedValueException('Failed to retrieve composer information for identifier '.$identifier.' in '.$this->getUrl());
            }

            $composer = JsonFile::parseJson($composer);

            if (!isset($composer['time'])) {
                $commit = json_decode(file_get_contents('http://github.com/api/v2/json/commits/show/'.$this->owner.'/'.$this->repository.'/'.$identifier), true);
                $composer['time'] = $commit['commit']['committed_date'];
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
            $tagsData = json_decode(file_get_contents('http://github.com/api/v2/json/repos/show/'.$this->owner.'/'.$this->repository.'/tags'), true);
            $this->tags = $tagsData['tags'];
        }
        return $this->tags;
    }

    /**
     * {@inheritDoc}
     */
    public function getBranches()
    {
        if (null === $this->branches) {
            $branchesData = json_decode(file_get_contents('http://github.com/api/v2/json/repos/show/'.$this->owner.'/'.$this->repository.'/branches'), true);
            $this->branches = $branchesData['branches'];
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
        return preg_match('#^(?:https?|git)://github\.com/([^/]+)/(.+?)(?:\.git)?$#', $url, $match);
    }
}
