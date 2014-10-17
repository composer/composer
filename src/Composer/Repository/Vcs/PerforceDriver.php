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
use Composer\Util\ProcessExecutor;
use Composer\Util\Perforce;

/**
 * @author Matt Whittom <Matt.Whittom@veteransunited.com>
 */
class PerforceDriver extends VcsDriver
{
    protected $depot;
    protected $branch;
    protected $perforce;
    protected $composerInfo;
    protected $composerInfoIdentifier;

    /**
     * {@inheritDoc}
     */
    public function initialize()
    {
        $this->depot = $this->repoConfig['depot'];
        $this->branch = '';
        if (!empty($this->repoConfig['branch'])) {
            $this->branch = $this->repoConfig['branch'];
        }

        $this->initPerforce($this->repoConfig);
        $this->perforce->p4Login($this->io);
        $this->perforce->checkStream($this->depot);

        $this->perforce->writeP4ClientSpec();
        $this->perforce->connectClient();

        return true;
    }

    private function initPerforce($repoConfig)
    {
        if (!empty($this->perforce)) {
            return;
        }

        $repoDir = $this->config->get('cache-vcs-dir') . '/' . $this->depot;
        $this->perforce = Perforce::create($repoConfig, $this->getUrl(), $repoDir, $this->process, $this->io);
    }

    /**
     * {@inheritDoc}
     */
    public function getComposerInformation($identifier)
    {
        if (!empty($this->composerInfoIdentifier)) {
            if (strcmp($identifier, $this->composerInfoIdentifier) === 0) {
                return $this->composerInfo;
            }
        }
        $composer_info = $this->perforce->getComposerInformation($identifier);

        return $composer_info;
    }

    /**
     * {@inheritDoc}
     */
    public function getRootIdentifier()
    {
        return $this->branch;
    }

    /**
     * {@inheritDoc}
     */
    public function getBranches()
    {
        $branches = $this->perforce->getBranches();

        return $branches;
    }

    /**
     * {@inheritDoc}
     */
    public function getTags()
    {
        $tags = $this->perforce->getTags();

        return $tags;
    }

    /**
     * {@inheritDoc}
     */
    public function getDist($identifier)
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function getSource($identifier)
    {
        $source = array(
            'type'      => 'perforce',
            'url'       => $this->repoConfig['url'],
            'reference' => $identifier,
            'p4user'    => $this->perforce->getUser()
        );

        return $source;
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
    public function hasComposerFile($identifier)
    {
        $this->composerInfo = $this->perforce->getComposerInformation('//' . $this->depot . '/' . $identifier);
        $this->composerInfoIdentifier = $identifier;

        return !empty($this->composerInfo);
    }

    /**
     * {@inheritDoc}
     */
    public function getContents($url)
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public static function supports(IOInterface $io, Config $config, $url, $deep = false)
    {
        if ($deep || preg_match('#\b(perforce|p4)\b#i', $url)) {
            return Perforce::checkServerExists($url, new ProcessExecutor($io));
        }

        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function cleanup()
    {
        $this->perforce->cleanupClientSpec();
        $this->perforce = null;
    }

    public function getDepot()
    {
        return $this->depot;
    }

    public function getBranch()
    {
        return $this->branch;
    }
}
