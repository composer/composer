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
    /** @var Perforce */
    protected $perforce;

    /**
     * {@inheritDoc}
     */
    public function doDownload(PackageInterface $package, $path, $url)
    {
        $ref = $package->getSourceReference();
        $label = $this->getLabelFromSourceReference($ref);

        $this->io->writeError('    Cloning ' . $ref);
        $this->initPerforce($package, $path, $url);
        $this->perforce->setStream($ref);
        $this->perforce->p4Login();
        $this->perforce->writeP4ClientSpec();
        $this->perforce->connectClient();
        $this->perforce->syncCodeBase($label);
        $this->perforce->cleanupClientSpec();
    }

    private function getLabelFromSourceReference($ref)
    {
        $pos = strpos($ref, '@');
        if (false !== $pos) {
            return substr($ref, $pos + 1);
        }

        return null;
    }

    public function initPerforce(PackageInterface $package, $path, $url)
    {
        if (!empty($this->perforce)) {
            $this->perforce->initializePath($path);

            return;
        }

        $repository = $package->getRepository();
        $repoConfig = null;
        if ($repository instanceof VcsRepository) {
            $repoConfig = $this->getRepoConfig($repository);
        }
        $this->perforce = Perforce::create($repoConfig, $url, $path, $this->process, $this->io);
    }

    private function getRepoConfig(VcsRepository $repository)
    {
        return $repository->getRepoConfig();
    }

    /**
     * {@inheritDoc}
     */
    public function doUpdate(PackageInterface $initial, PackageInterface $target, $path, $url)
    {
        $this->doDownload($target, $path, $url);
    }

    /**
     * {@inheritDoc}
     */
    public function getLocalChanges(PackageInterface $package, $path)
    {
        $this->io->writeError('Perforce driver does not check for local changes before overriding', true);

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

    /**
     * {@inheritDoc}
     */
    protected function hasMetadataRepository($path)
    {
        return true;
    }
}
