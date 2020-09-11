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
use Composer\Cache;
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
    /** @var Perforce */
    protected $perforce;

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
        $this->perforce->p4Login();
        $this->perforce->checkStream();

        $this->perforce->writeP4ClientSpec();
        $this->perforce->connectClient();

        return true;
    }

    private function initPerforce($repoConfig)
    {
        if (!empty($this->perforce)) {
            return;
        }

        if (!Cache::isUsable($this->config->get('cache-vcs-dir'))) {
            throw new \RuntimeException('PerforceDriver requires a usable cache directory, and it looks like you set it to be disabled');
        }

        $repoDir = $this->config->get('cache-vcs-dir') . '/' . $this->depot;
        $this->perforce = Perforce::create($repoConfig, $this->getUrl(), $repoDir, $this->process, $this->io);
    }

    /**
     * {@inheritdoc}
     */
    public function getFileContent($file, $identifier)
    {
        return $this->perforce->getFileContent($file, $identifier);
    }

    /**
     * {@inheritdoc}
     */
    public function getChangeDate($identifier)
    {
        return null;
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
        return $this->perforce->getBranches();
    }

    /**
     * {@inheritDoc}
     */
    public function getTags()
    {
        return $this->perforce->getTags();
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
        return array(
            'type' => 'perforce',
            'url' => $this->repoConfig['url'],
            'reference' => $identifier,
            'p4user' => $this->perforce->getUser(),
        );
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
        $composerInfo = $this->perforce->getComposerInformation('//' . $this->depot . '/' . $identifier);

        return !empty($composerInfo);
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
