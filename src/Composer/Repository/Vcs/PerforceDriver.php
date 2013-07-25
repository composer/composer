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
    protected $rootIdentifier;
    protected $depot;
    protected $perforce;

    /**
     * {@inheritDoc}
     */
    public function initialize()
    {
        print ("\nPerforceDriver:initialize\n");
        $this->rootIdentifier = "mainline";
        $this->depot = $this->repoConfig['depot'];

        $stream = "//$this->depot/$this->rootIdentifier";
        $repoDir = $this->config->get('cache-vcs-dir') . "/$this->depot";
        $this->perforce = new Perforce($stream, $this->getUrl(), $repoDir);

        $this->perforce->p4Login($this->io);
        $this->perforce->writeP4ClientSpec();
        $this->perforce->syncCodeBase();

        return true;
    }




    /**
     * {@inheritDoc}
     */
    public function getComposerInformation($identifier)
    {
        print ("PerforceDriver:getComposerInformation - identifier: $identifier\n");
        $composer_info =$this->perforce->getComposerInformation($identifier);
        return $composer_info;
    }

    /**
     * {@inheritDoc}
     */
    public function getRootIdentifier()
    {
        print ("PerforceDriver:getRootIdentifier\n");
        return $this->rootIdentifier;
    }

    /**
     * {@inheritDoc}
     */
    public function getBranches()
    {
        print ("PerforceDriver:getBranches\n");
        $command = "p4 streams //$this->depot/...";
        $result = shell_exec($command);

        $resArray = explode("\n", $result);
        $branches = array();
        foreach ($resArray as $line){
            $resBits = explode(" ", $line);
            if (count($resBits) > 4){
                $branch = substr($resBits[4], 1, strlen($resBits[4])-2);
                $branches[$branch] = $resBits[1];
            }
        }
        $branches['master'] = $branches['mainline'];
        return $branches;
    }

    /**
     * {@inheritDoc}
     */
    public function getTags()
    {
        print ("PerforceDriver:getTags\n");
        return array();
    }

    /**
     * {@inheritDoc}
     */
    public function getDist($identifier)
    {
        print("\nPerforceDriver:getDist: identifier: $identifier\n");
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function getSource($identifier)
    {
        print ("\nPerforceDriver:getSource - identifier: $identifier\n");

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
        print ("PerforceDriver:getUrl\n");
        return $this->url;
    }

    /**
     * {@inheritDoc}
     */
    public function hasComposerFile($identifier)
    {
        print ("\nPerforceDriver:hasComposerFile - identifier: $identifier\n");
        $composerFile = $this->perforce->getComposerFilePath($identifier);
        print ("returning: " . var_export(file_exists($composerFile),true) . "\n");
        return file_exists($composerFile);
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
        print ("PerforceDriver:supports - url: $url\n");
        return Perforce::checkServerExists($url);
    }
}
