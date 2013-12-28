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

        $this->io->write('    Cloning ' . $ref);
        $this->initPerforce($package, $path);
        $this->perforce->setStream($ref);
        $this->perforce->p4Login($this->io);
        $this->perforce->writeP4ClientSpec();
        $this->perforce->connectClient();
        $this->perforce->syncCodeBase($label);
        $this->perforce->cleanupClientSpec();
    }

    public function initPerforce($package, $path)
    {
        if ($this->perforce) {
            $this->perforce->initializePath($path);
            return;
        }

        $repository = $package->getRepository();
        $repoConfig = null;
        if ($repository instanceof VcsRepository) {
            $repoConfig = $this->getRepoConfig($repository);
        }
        $this->perforce = Perforce::create($repoConfig, $package->getSourceUrl(), $path);
    }

    private function getRepoConfig(VcsRepository $repository)
    {
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
        $this->io->write('Perforce driver does not check for local changes before overriding', true);

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

    public function setPerforce($perforce)
    {
        $this->perforce = $perforce;
    }
}
