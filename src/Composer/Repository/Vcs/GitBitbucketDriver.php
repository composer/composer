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
class GitBitbucketDriver extends BitbucketDriver implements VcsDriverInterface
{



    /**
     * {@inheritDoc}
     */
    public function getRootIdentifier()
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getRootIdentifier();
        }

        if (null === $this->rootIdentifier) {
            $resource = $this->getScheme() . '://api.bitbucket.org/1.0/repositories/'.$this->owner.'/'.$this->repository;
            $repoData = JsonFile::parseJson($this->getContentsWithOAuthCredentials($resource, true), $resource);
            $this->hasIssues = !empty($repoData['has_issues']);
            $this->rootIdentifier = !empty($repoData['main_branch']) ? $repoData['main_branch'] : 'master';
        }

        return $this->rootIdentifier;
    }

    /**
     * {@inheritDoc}
     */
    public function getUrl()
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getUrl();
        }

        return 'https://' . $this->originUrl . '/'.$this->owner.'/'.$this->repository.'.git';
    }

    /**
     * {@inheritDoc}
     */
    public function getSource($identifier)
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getSource($identifier);
        }

        return array('type' => 'git', 'url' => $this->getUrl(), 'reference' => $identifier);
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
    public function getTags()
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getTags();
        }

        if (null === $this->tags) {
            $resource = $this->getScheme() . '://api.bitbucket.org/1.0/repositories/'.$this->owner.'/'.$this->repository.'/tags';
            $tagsData = JsonFile::parseJson($this->getContentsWithOAuthCredentials($resource), $resource);
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
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getBranches();
        }

        if (null === $this->branches) {
            $resource =  $this->getScheme() . '://api.bitbucket.org/1.0/repositories/'.$this->owner.'/'.$this->repository.'/branches';
            $branchData = JsonFile::parseJson($this->getContentsWithOAuthCredentials($resource), $resource);
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
        if (!preg_match('#^https?://bitbucket\.org/([^/]+)/(.+?)\.git$#', $url)) {
            return false;
        }

        if (!extension_loaded('openssl')) {
            $io->writeError('Skipping Bitbucket git driver for '.$url.' because the OpenSSL PHP extension is missing.', true, IOInterface::VERBOSE);

            return false;
        }

        return true;
    }

    /**
     * @param string $url
     */
    protected function setupFallbackDriver($url)
    {
        $this->fallbackDriver = new GitDriver(
            array('url' => $url),
            $this->io,
            $this->config,
            $this->process,
            $this->remoteFilesystem
        );
        $this->fallbackDriver->initialize();
    }

    /**
     * {@inheritdoc}
     */
    protected function generateSshUrl()
    {
        return 'git@' . $this->originUrl . ':' . $this->owner.'/'.$this->repository.'.git';
    }
}
