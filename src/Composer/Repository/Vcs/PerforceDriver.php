<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 *  Contributor: matt-whittom
 *  Date: 7/17/13
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Repository\Vcs;

use Composer\IO\IOInterface;
use Composer\Util\ProcessExecutor;
use Composer\Util\Filesystem;
use Composer\Util\Perforce;

/**
 * @author matt-whittom <>
 */
class PerforceDriver extends VcsDriver {
    protected $depot;
    protected $branch;
    protected $perforce;

    /**
     * {@inheritDoc}
     */
    public function initialize() {
        $this->depot  = $this->repoConfig['depot'];
        $this->branch = "";
        if (isset($this->repoConfig['branch'])) {
            $this->branch = $this->repoConfig['branch'];
        }

        $repoDir = $this->config->get('cache-vcs-dir') . "/$this->depot";
        if (!isset($this->perforce)) {
            $this->perforce = new Perforce($this->depot, $this->branch, $this->getUrl(), $repoDir, $this->process);
        }

        $this->perforce->p4Login($this->io);
        $this->perforce->checkStream($this->depot);

        $this->perforce->writeP4ClientSpec();
        $this->perforce->connectClient();

        return TRUE;
    }

    public function injectPerforce(Perforce $perforce) {
        $this->perforce = $perforce;
    }


    /**
     * {@inheritDoc}
     */
    public function getComposerInformation($identifier) {
        $composer_info = $this->perforce->getComposerInformation($identifier);

        return $composer_info;
    }

    /**
     * {@inheritDoc}
     */
    public function getRootIdentifier() {
        return $this->branch;
    }

    /**
     * {@inheritDoc}
     */
    public function getBranches() {
        $branches = $this->perforce->getBranches();

        return $branches;
    }

    /**
     * {@inheritDoc}
     */
    public function getTags() {
        $tags = $this->perforce->getTags();
        return $tags;
    }

    /**
     * {@inheritDoc}
     */
    public function getDist($identifier) {
        return NULL;
    }

    /**
     * {@inheritDoc}
     */
    public function getSource($identifier) {
        $source = array(
            'type'      => 'perforce',
            'url'       => $this->repoConfig['url'],
            'reference' => $identifier
        );

        return $source;
    }

    /**
     * {@inheritDoc}
     */
    public function getUrl() {
        return $this->url;
    }

    /**
     * {@inheritDoc}
     */
    public function hasComposerFile($identifier) {
        $composer_info = $this->perforce->getComposerInformation("//$this->depot/$identifier");
        $result = strlen(trim($composer_info)) > 0;

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function getContents($url) {
        return FALSE;
    }

    /**
     * {@inheritDoc}
     */
    public static function supports(IOInterface $io, $url, $deep = FALSE) {
        return Perforce::checkServerExists($url, new ProcessExecutor);
    }
}
