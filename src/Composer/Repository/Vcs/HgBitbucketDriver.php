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
use Composer\Json\JsonFile;
use Composer\IO\IOInterface;

/**
 * @author Per Bernhardt <plb@webfactory.de>
 */
class HgBitbucketDriver extends VcsDriver
{
    protected $owner;
    protected $repository;
    protected $tags;
    protected $branches;
    protected $rootIdentifier;
    protected $infoCache = array();

    /**
     * {@inheritDoc}
     */
    public function initialize()
    {
        preg_match('#^https://bitbucket\.org/([^/]+)/([^/]+)/?$#', $this->url, $match);
        $this->owner = $match[1];
        $this->repository = $match[2];
        $this->originUrl = 'bitbucket.org';
    }

    /**
     * {@inheritDoc}
     */
    public function getRootIdentifier()
    {
        if (null === $this->rootIdentifier) {
            $resource = $this->getScheme() . '://bitbucket.org/api/1.0/repositories/'.$this->owner.'/'.$this->repository.'/tags';
            $repoData = JsonFile::parseJson($this->getContents($resource), $resource);
            if (array() === $repoData || !isset($repoData['tip'])) {
                throw new \RuntimeException($this->url.' does not appear to be a mercurial repository, use '.$this->url.'.git if this is a git bitbucket repository');
            }
            $this->rootIdentifier = $repoData['tip']['raw_node'];
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
        return array('type' => 'hg', 'url' => $this->getUrl(), 'reference' => $identifier);
    }

    /**
     * {@inheritDoc}
     */
    public function getDist($identifier)
    {
        $url = $this->getScheme() . '://bitbucket.org/'.$this->owner.'/'.$this->repository.'/get/'.$identifier.'.zip';

        return array('type' => 'zip', 'url' => $url, 'reference' => $identifier, 'shasum' => '');
    }

    /**
     * {@inheritDoc}
     */
    public function getComposerInformation($identifier)
    {
        if (!isset($this->infoCache[$identifier])) {
            $resource = $this->getScheme() . '://bitbucket.org/api/1.0/repositories/'.$this->owner.'/'.$this->repository.'/src/'.$identifier.'/composer.json';
            $repoData = JsonFile::parseJson($this->getContents($resource), $resource);

            // Bitbucket does not send different response codes for found and
            // not found files, so we have to check the response structure.
            // found:     {node: ..., data: ..., size: ..., ...}
            // not found: {node: ..., files: [...], directories: [...], ...}

            if (!array_key_exists('data', $repoData)) {
                return;
            }

            $composer = JsonFile::parseJson($repoData['data'], $resource);

            if (empty($composer['time'])) {
                $resource = $this->getScheme() . '://bitbucket.org/api/1.0/repositories/'.$this->owner.'/'.$this->repository.'/changesets/'.$identifier;
                $changeset = JsonFile::parseJson($this->getContents($resource), $resource);
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
            $resource = $this->getScheme() . '://bitbucket.org/api/1.0/repositories/'.$this->owner.'/'.$this->repository.'/tags';
            $tagsData = JsonFile::parseJson($this->getContents($resource), $resource);
            $this->tags = array();
            foreach ($tagsData as $tag => $data) {
                $this->tags[$tag] = $data['raw_node'];
            }
            unset($this->tags['tip']);
        }

        return $this->tags;
    }

    /**
     * {@inheritDoc}
     */
    public function getBranches()
    {
        if (null === $this->branches) {
            $resource = $this->getScheme() . '://bitbucket.org/api/1.0/repositories/'.$this->owner.'/'.$this->repository.'/branches';
            $branchData = JsonFile::parseJson($this->getContents($resource), $resource);
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
    public static function supports(IOInterface $io, Config $config, $url, $deep = false)
    {
        if (!preg_match('#^https://bitbucket\.org/([^/]+)/([^/]+)/?$#', $url)) {
            return false;
        }

        if (!extension_loaded('openssl')) {
            if ($io->isVerbose()) {
                $io->write('Skipping Bitbucket hg driver for '.$url.' because the OpenSSL PHP extension is missing.');
            }

            return false;
        }

        return true;
    }
}
