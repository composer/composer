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
use Composer\IO\IOInterface;

/**
 * @author Per Bernhardt <plb@webfactory.de>
 */
class HgBitbucketDriver extends BitbucketDriver
{
    /**
     * @inheritDoc
     */
    public function getRootIdentifier()
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getRootIdentifier();
        }

        if (null === $this->rootIdentifier) {
            if (!$this->getRepoData()) {
                if (!$this->fallbackDriver) {
                    throw new \LogicException('A fallback driver should be setup if getRepoData returns false');
                }

                return $this->fallbackDriver->getRootIdentifier();
            }

            if ($this->vcsType !== 'hg') {
                throw new \RuntimeException(
                    $this->url.' does not appear to be a mercurial repository, use '.
                    $this->cloneHttpsUrl.' if this is a git bitbucket repository'
                );
            }

            $mainBranchData = $this->getMainBranchData();
            $this->rootIdentifier = !empty($mainBranchData['name']) ? $mainBranchData['name'] : 'default';
        }

        return $this->rootIdentifier;
    }

    /**
     * @inheritDoc
     */
    public static function supports(IOInterface $io, Config $config, $url, $deep = false)
    {
        if (!preg_match('#^https?://bitbucket\.org/([^/]+)/([^/]+)/?$#i', $url)) {
            return false;
        }

        if (!extension_loaded('openssl')) {
            $io->writeError('Skipping Bitbucket hg driver for '.$url.' because the OpenSSL PHP extension is missing.', true, IOInterface::VERBOSE);

            return false;
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    protected function setupFallbackDriver($url)
    {
        $this->fallbackDriver = new HgDriver(
            array('url' => $url),
            $this->io,
            $this->config,
            $this->httpDownloader,
            $this->process
        );
        $this->fallbackDriver->initialize();
    }

    /**
     * @inheritDoc
     */
    protected function generateSshUrl()
    {
        return 'ssh://hg@' . $this->originUrl . '/' . $this->owner.'/'.$this->repository;
    }
}
