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

use Composer\Json\JsonFile;
use Composer\IO\IOInterface;

/**
 * @author Per Bernhardt <plb@webfactory.de>
 */
class GitBitbucketDriver extends VcsDriver implements VcsDriverInterface
{
    protected $owner;
    protected $repository;
    protected $tags;
    protected $branches;
    protected $rootIdentifier;
    protected $infoCache = array();

    public function __construct($url, IOInterface $io)
    {
        preg_match('#^https://bitbucket\.org/([^/]+)/(.+?)\.git$#', $url, $match);
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
            $repoData = json_decode($this->getContents($this->getScheme() . '://api.bitbucket.org/1.0/repositories/'.$this->owner.'/'.$this->repository), true);
            $this->rootIdentifier = !empty($repoData['main_branch']) ? $repoData['main_branch'] : 'master';
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
        $url = $this->getScheme() . '://bitbucket.org/'.$this->owner.'/'.$this->repository.'/get/'.$label.'.zip';

        return array('type' => 'zip', 'url' => $url, 'reference' => $label, 'shasum' => '');
    }

    /**
     * {@inheritDoc}
     */
    public function getComposerInformation($identifier)
    {
        if (!isset($this->infoCache[$identifier])) {
            $composer = $this->getContents($this->getScheme() . '://bitbucket.org/'.$this->owner.'/'.$this->repository.'/raw/'.$identifier.'/composer.json');
            if (!$composer) {
                throw new \UnexpectedValueException('Failed to retrieve composer information for identifier '.$identifier.' in '.$this->getUrl());
            }

            $composer = JsonFile::parseJson($composer);

            if (!isset($composer['time'])) {
                $changeset = json_decode($this->getContents($this->getScheme() . '://api.bitbucket.org/1.0/repositories/'.$this->owner.'/'.$this->repository.'/changesets/'.$identifier), true);
                $composer['time'] = $changeset['timestamp'];
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
            $tagsData = json_decode($this->getContents($this->getScheme() . '://api.bitbucket.org/1.0/repositories/'.$this->owner.'/'.$this->repository.'/tags'), true);
            $this->tags = array();
            foreach ($tagsData as $tag => $data) {
                $this->tags[$tag] = $data['raw_node'];
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
            $branchData = json_decode($this->getContents($this->getScheme() . '://api.bitbucket.org/1.0/repositories/'.$this->owner.'/'.$this->repository.'/branches'), true);
            $this->branches = array();
            foreach ($branchData as $branch => $data) {
                $this->branches[$branch] = $data['raw_node'];
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
        return preg_match('#^https://bitbucket\.org/([^/]+)/(.+?)\.git$#', $url);
    }
}
