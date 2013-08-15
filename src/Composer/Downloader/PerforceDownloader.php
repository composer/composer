<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 *  Contributor: Matt Whittom <Matt.Whittom@veteransunited.com>
 *  Date: 7/17/13
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */


namespace Composer\Downloader;

use Composer\Package\PackageInterface;
use Composer\Repository\VcsRepository;
use Composer\Util\Perforce;

/**
 * @author Matt Whittom <Matt.Whittom@veteransunited.com>
 */
class PerforceDownloader extends VcsDownloader
{
    protected $perforce;
    protected $perforceInjected = false;

    /**
     * {@inheritDoc}
     */
    public function doDownload(PackageInterface $package, $path)
    {
        $ref = $package->getSourceReference();
        $label = $package->getPrettyVersion();

        $this->io->write("    Cloning ".$ref);
        $this->initPerforce($package, $path);
        $this->perforce->setStream($ref);
        $this->perforce->queryP4User($this->io);
        $this->perforce->writeP4ClientSpec();
        $this->perforce->connectClient();
        $this->perforce->syncCodeBase($label);
        $this->perforce->cleanupClientSpec();
    }

    private function initPerforce($package, $path){
        if ($this->perforceInjected){
            return;
        }
        $repository = $package->getRepository();
        $repoConfig = $this->getRepoConfig($repository);
        $this->perforce = Perforce::createPerforce($repoConfig, $package->getSourceUrl(), $path);
    }

    public function injectPerforce($perforce){
        $this->perforce = $perforce;
        $this->perforceInjected = true;
    }

    private function getRepoConfig(VcsRepository $repository){
        return $repository->getRepoConfig();
    }

    /**
     * {@inheritDoc}
     */
    public function doUpdate(PackageInterface $initial, PackageInterface $target, $path)
    {
        $this->doDownload($target, $path);
    }

    /**
     * {@inheritDoc}
     */
    public function getLocalChanges(PackageInterface $package, $path)
    {
        print ("Perforce driver does not check for local changes before overriding\n");
        return;
    }


    /**
     * {@inheritDoc}
     */
    protected function getCommitLogs($fromReference, $toReference, $path)
    {
        $commitLogs = $this->perforce->getCommitLogs($fromReference, $toReference);
        return $commitLogs;
    }

}
