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
            $repoData = $this->getRepoData();

            if ($repoData['scm'] !== 'git') {
                throw new \RuntimeException(
                    $this->url.' does not appear to be a git repository, use '.
                    $this->cloneHttpsUrl.' if this is a mercurial bitbucket repository'
                );
            }

            $resource = sprintf(
                'https://api.bitbucket.org/1.0/repositories/%s/%s/main-branch',
                $this->owner,
                $this->repository
            );
            $main_branch_data = JsonFile::parseJson($this->getContentsWithOAuthCredentials($resource, true), $resource);
            $this->rootIdentifier = !empty($main_branch_data['name']) ? $main_branch_data['name'] : 'master';
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

        return parent::getUrl();
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
    public function getTags()
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getTags();
        }

        return parent::getTags();
    }

    /**
     * {@inheritDoc}
     */
    public function getBranches()
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getBranches();
        }

        return parent::getBranches();
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
}
