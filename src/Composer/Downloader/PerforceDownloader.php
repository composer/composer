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
    private $hasStashedChanges = false;

    /**
     * {@inheritDoc}
     */
    public function doDownload(PackageInterface $package, $path)
    {
        $ref = $package->getSourceReference();

        $perforce = new Perforce($ref, $package->getSourceUrl(), $path);
        $perforce->writeP4ClientSpec();
        $perforce->syncCodeBase();
    }

    /**
     * {@inheritDoc}
     */
    public function doUpdate(PackageInterface $initial, PackageInterface $target, $path)
    {
        print("PerforceDownloader:doUpdate\n");
    }

    /**
     * {@inheritDoc}
     */
    public function getLocalChanges($path)
    {
        print("PerforceDownloader:getLocalChanges\n");
    }


    /**
     * {@inheritDoc}
     */
    protected function getCommitLogs($fromReference, $toReference, $path)
    {
        print("PerforceDownloader:getCommitLogs\n");
    }

}
