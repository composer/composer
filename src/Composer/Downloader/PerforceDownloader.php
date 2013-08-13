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

namespace Composer\Downloader;

use Composer\Package\PackageInterface;
use Composer\Repository\VcsRepository;
use Composer\Util\Perforce;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PerforceDownloader extends VcsDownloader
{
    protected $perforce;

    /**
     * {@inheritDoc}
     */
    public function doDownload(PackageInterface $package, $path)
    {
        $ref = $package->getSourceReference();
        $label = $package->getPrettyVersion();

        $this->initPerforce($package, $path);
        $this->perforce->setStream($ref);
        $this->perforce->queryP4User($this->io);
        $this->perforce->writeP4ClientSpec();
        $this->perforce->connectClient();
        $this->perforce->syncCodeBase($label);
    }

    private function initPerforce($package, $path){
        if (isset($this->perforce)){
            return;
        }
        $repository = $package->getRepository();
        $repoConfig = $this->getRepoConfig($repository);
        $this->perforce = new Perforce($repoConfig, $package->getSourceUrl(), $path);
    }

    public function injectPerforce($perforce){
        $this->perforce = $perforce;
    }

    private function getRepoConfig(VcsRepository $repository){
        return $repository->getRepoConfig();
    }

    /**
     * {@inheritDoc}
     */
    public function doUpdate(PackageInterface $initial, PackageInterface $target, $path)
    {
        print("PerforceDownloader:doUpdate\n");
        throw new Exception("Unsupported Operation: PerforceDownloader:doUpdate");
    }

    /**
     * {@inheritDoc}
     */
    public function getLocalChanges($path)
    {
        print("PerforceDownloader:getLocalChanges\n");
        throw new Exception("Unsupported Operation: PerforceDownloader:getLocalChanges");
    }


    /**
     * {@inheritDoc}
     */
    protected function getCommitLogs($fromReference, $toReference, $path)
    {
        print("PerforceDownloader:getCommitLogs\n");
        throw new Exception("Unsupported Operation: PerforceDownloader:getCommitLogs");
    }

}
