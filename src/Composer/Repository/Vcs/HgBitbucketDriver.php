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
class HgBitbucketDriver extends BitbucketDriver
{
    /**
     * {@inheritDoc}
     */
    public function getRootIdentifier()
    {
        if (null === $this->rootIdentifier) {
            $repoData = $this->getRepoData();

            if ($repoData['scm'] !== 'hg') {
                throw new \RuntimeException(
                    $this->url.' does not appear to be a mercurial repository, use '.
                    $this->cloneHttpsUrl.' if this is a git bitbucket repository'
                );
            }

            $resource = sprintf(
                'https://api.bitbucket.org/1.0/repositories/%s/%s/main-branch',
                $this->owner,
                $this->repository
            );
            $main_branch_data = JsonFile::parseJson($this->getContentsWithOAuthCredentials($resource, true), $resource);
            $this->rootIdentifier = !empty($main_branch_data['name']) ? $main_branch_data['name'] : 'default';
        }

        return $this->rootIdentifier;
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
    public function getTags()
    {
        parent::getTags();

        if (isset($this->tags['tip'])) {
            unset($this->tags['tip']);
        }

        return $this->tags;
    }

    /**
     * {@inheritDoc}
     */
    public static function supports(IOInterface $io, Config $config, $url, $deep = false)
    {
        if (!preg_match('#^https?://bitbucket\.org/([^/]+)/([^/]+)/?$#', $url)) {
            return false;
        }

        if (!extension_loaded('openssl')) {
            $io->writeError('Skipping Bitbucket hg driver for '.$url.' because the OpenSSL PHP extension is missing.', true, IOInterface::VERBOSE);

            return false;
        }

        return true;
    }

    protected function setupFallbackDriver($url)
    {
        $this->fallbackDriver = new HgDriver(
            array('url' => $url),
            $this->io,
            $this->config,
            $this->process,
            $this->remoteFilesystem
        );
        $this->fallbackDriver->initialize();
    }
}
