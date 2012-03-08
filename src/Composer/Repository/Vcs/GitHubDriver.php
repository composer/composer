<?php

namespace Composer\Repository\Vcs;

use Composer\Json\JsonFile;
use Composer\IO\IOInterface;

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

    public function __construct($url, IOInterface $io)
    {
        preg_match('#^(?:https?|git)://github\.com/([^/]+)/(.+?)(?:\.git)?$#', $url, $match);
        $this->owner = $match[1];
        $this->repository = $match[2];

        parent::__construct($url, $io);
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
            $repoData = JsonFile::parseJson($this->getContents($this->getScheme() . '://api.github.com/repos/'.$this->owner.'/'.$this->repository));
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
        $url = $this->getScheme() . '://github.com/'.$this->owner.'/'.$this->repository.'/zipball/'.$label;

        return array('type' => 'zip', 'url' => $url, 'reference' => $label, 'shasum' => '');
    }

    /**
     * {@inheritDoc}
     */
    public function getComposerInformation($identifier)
    {
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
        if (null === $this->branches) {
            $branchData = JsonFile::parseJson($this->getContents($this->getScheme() . '://api.github.com/repos/'.$this->owner.'/'.$this->repository.'/branches'));
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
    public static function supports($url, $deep = false)
    {
        return extension_loaded('openssl') && preg_match('#^(?:https?|git)://github\.com/([^/]+)/(.+?)(?:\.git)?$#', $url);
    }
}
