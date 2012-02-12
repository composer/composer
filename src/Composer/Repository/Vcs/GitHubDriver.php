<?php

namespace Composer\Repository\Vcs;

use Composer\Json\JsonFile;
use Composer\IO\IOInterface;

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
            $repoData = $this->getFromApi('repos/'.$this->owner.'/'.$this->repository);
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
                throw new \UnexpectedValueException('Failed to retrieve composer information for identifier '.$identifier.' in '.$this->getUrl());
            }

            $composer = JsonFile::parseJson($composer);

            if (!isset($composer['time'])) {
                $commit = $this->getFromApi('repos/'.$this->owner.'/'.$this->repository.'/commits/'.$identifier);
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
            $tagsData = $this->getFromApi('repos/'.$this->owner.'/'.$this->repository.'/tags');
            $this->tags = array();
            foreach ($tagsData as $tag) {
                $this->tags[$tag['name']] = $tag['commit']['sha'];
            }

            $this->filterTags();
        }

        return $this->tags;
    }

    /**
     * Filter out invalid tags
     *
     * @see Composer\Repository\Vcs\GitDriver::filterTags
     */
    protected function filterTags()
    {
        $invalidShas = array();

        $refs = $this->getFromApi('repos/'.$this->owner.'/'.$this->repository.'/git/refs/notes/composer');
        if (!$refs) {
            return;
        }
        $url = $refs['object']['url'];

        $commit = json_decode($this->getContents($url), true);
        $url = $commit['tree']['url'];

        $tree = json_decode($this->getContents($url), true);
        foreach ($tree['tree'] as $blob) {
            $url = $blob['url'];
            $note = json_decode($this->getContents($url), true);

            $sha = $blob['path'];

            $content = ('base64' === $note['encoding'])
                ? trim(base64_decode(trim($note['content'])))
                : trim($note['content']);
            $content = explode("\n", $content);

            if (in_array('invalid', $content)) {
                $invalidShas[$sha] = $sha;
            }
        }

        foreach ($this->tags as $tag => $sha) {
            if (isset($invalidShas[$sha]) && $sha === $invalidShas[$sha]) {
                unset($this->tags[$tag]);
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getBranches()
    {
        if (null === $this->branches) {
            $branchData = $this->getFromApi('repos/'.$this->owner.'/'.$this->repository.'/branches');
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
     * Make a request to the GitHub API
     */
    protected function getFromApi($path)
    {
        $url = $this->getScheme().'://api.github.com/'.$path;
        return json_decode($this->getContents($url), true);
    }

    /**
     * {@inheritDoc}
     */
    public static function supports($url, $deep = false)
    {
        return extension_loaded('openssl') && preg_match('#^(?:https?|git)://github\.com/([^/]+)/(.+?)(?:\.git)?$#', $url);
    }
}
