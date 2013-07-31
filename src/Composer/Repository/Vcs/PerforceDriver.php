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
use Composer\Util\Filesystem;
use Composer\Util\Perforce;

/**
 * @author matt-whittom <>
 */
class PerforceDriver extends VcsDriver
{
    protected $depot;
    protected $branch;
    protected $perforce;

    /**
     * {@inheritDoc}
     */
    public function initialize()
    {
        $this->depot = $this->repoConfig['depot'];
        $this->branch = "";
        if (isset($this->repoConfig['branch'])){
            $this->branch = $this->repoConfig['branch'];
        }

        $repoDir = $this->config->get('cache-vcs-dir') . "/$this->depot";
        $this->perforce = new Perforce($this->depot, $this->branch, $this->getUrl(), $repoDir);

        $this->perforce->p4Login($this->io);
        $this->perforce->checkStream($this->depot);

        $this->perforce->writeP4ClientSpec();
//        $this->perforce->syncCodeBase();

        return true;
    }




    /**
     * {@inheritDoc}
     */
    public function getComposerInformation($identifier)
    {
        $composer_info =$this->perforce->getComposerInformation($identifier);
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
        $source = array (
            'type' => 'perforce',
            'url' => $this->repoConfig['url'],
            'reference' => $identifier
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
        $composerFile = $this->perforce->getComposerFilePath($identifier);
        if (!file_exists(filename)){
            $composer_info = $this->perforce->getComposerInformation();
            $result = strlen(trim($composer_info))>0;
            return $result;
        }
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function getContents($url)
    {
        print ("\nPerforceDriver:getContents - url: $url\n");
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public static function supports(IOInterface $io, $url, $deep = false)
    {
        return Perforce::checkServerExists($url);
    }
}
