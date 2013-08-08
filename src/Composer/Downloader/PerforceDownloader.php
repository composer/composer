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
use Composer\Util\Perforce;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PerforceDownloader extends VcsDownloader
{
    /**
     * {@inheritDoc}
     */
    public function doDownload(PackageInterface $package, $path)
    {
        $ref = $package->getSourceReference();
        $label = $package->getPrettyVersion();

        $repository = $package->getRepository();
        //assume repository is a Perforce Repository

        $reflector = new \ReflectionClass($repository);
        $repoConfigProperty = $reflector->getProperty("repoConfig");
        $repoConfigProperty->setAccessible(true);
        $repoConfig = $repoConfigProperty->getValue($repository);

        $p4user = "";
        if (isset($repoConfig['p4user'])) {
            $p4user = $repoConfig['p4user'];
        }
        $p4password = "";
        if (isset($repoConfig['p4password'])) {
            $p4password = $repoConfig['p4password'];
        }

//        print("Perforce Downloader:doDownload - repoConfig:" . var_dump($repoConfig, true) . "\n\n");
        $perforce = new Perforce("", "", $package->getSourceUrl(), $path, null, $p4user, $p4password);
        $perforce->setStream($ref);
        $perforce->queryP4User($this->io);
        $perforce->writeP4ClientSpec();
        $perforce->connectClient();
        $perforce->syncCodeBase($label);
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
