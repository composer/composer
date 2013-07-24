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

#use Composer\Downloader\TransportException;
#use Composer\Json\JsonFile;
#use Composer\Cache;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Composer\Util\Perforce;
#use Composer\Util\RemoteFilesystem;
#use Composer\Util\GitHub;

/**
 * @author matt-whittom <>
 */
class PerforceDriver extends VcsDriver
{
//    protected $cache;
//    protected $owner;
//    protected $repository;
//    protected $tags;
//    protected $branches;
    protected $rootIdentifier = 'mainline';
    protected $repoDir;
//    protected $hasIssues;
//    protected $infoCache = array();
//    protected $isPrivate = false;
    protected $depot;
    protected $p4client;

    /**
     * {@inheritDoc}
     */
    public function initialize()
    {
        print ("PerforceDriver:initialize\n");
        $this->depot = $this->repoConfig['depot'];
        $this->p4client = "composer_perforce_$this->depot";
        $this->repoDir = $this->config->get('cache-vcs-dir') . "/$this->depot";
        $clientSpec = $this->config->get('cache-dir') . "/perforce/$this->p4client.p4.spec";

        $this->p4Login();

        $fs = new Filesystem();
        $fs->ensureDirectoryExists($this->repoDir);

        $stream = "//$this->depot/$this->rootIdentifier";
        $perforce = new Perforce();
        $perforce->writeP4ClientSpec($clientSpec, $this->repoDir, $this->p4client, $stream);
        $perforce->syncCodeBase($clientSpec, $this->repoDir, $this->p4client);

        return true;
    }

    protected function p4Login(){
        $password = trim(shell_exec('echo $P4PASSWD'));
        $command = "echo $password | p4 login -a ";
        shell_exec($command);
    }



    /**
     * {@inheritDoc}
     */
    public function getComposerInformation($identifier)
    {
        print ("PerforceDriver:getComposerInformation: $identifier\n");
        $command = "p4 print $identifier/composer.json";
        $result = shell_exec($command);
        $index = strpos($result, "{");
        if ($index === false){
            return;
        }
        if ($index >=0){
           $rawData = substr($result, $index);
            $composer_info = json_decode($rawData, true);
            print ("ComposerInfo is:".var_export($composer_info, true) . "\n");
            return $composer_info;
        }


//   Basically, read the composer.json file from the project.
//
//        Git stuff:
//        ..getComposerInfo is: array (
//        'support' =>
//        array (
//            'source' => 'http://github.com/composer/packagist',
//        ),
//        'time' => '2012-09-10',
//    )
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
        //return $branch->$identifier
        //getComposer($identifier)
        //validate($branch)
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
        print ("PerforceDriver:getBranches - returning branches:".var_export($branches, true)."\n");
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
        print ("PerforceDriver:getDist: $identifier\n");
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function getSource($identifier)
    {
        print ("PerforceDriver:getSource: $identifier\n");

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

    }

    /**
     * {@inheritDoc}
     */
    public function hasComposerFile($identifier)
    {
        print ("PerforceDriver:hasComposerFile: $identifier\n");

        //Does the project have a composer file?
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function getContents($url)
    {
        print("PerforceDriver:getContents - url: $url");
    }

    /**
     * {@inheritDoc}
     */
    public static function supports(IOInterface $io, $url, $deep = false)
    {
        print ("PerforceDriver:supports\n");

        print ("\nChecking url for support: $url\n\n");
        if (preg_match('#(^perforce)#', $url)) {
            return true;
        }
        return false;
    }
}
