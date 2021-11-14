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
    /** @var Perforce|null */
    protected $perforce;

    /**
     * @inheritDoc
     */
    protected function doDownload(PackageInterface $package, $path, $url, PackageInterface $prevPackage = null)
    {
        return \React\Promise\resolve();
    }

    /**
     * @inheritDoc
     */
    public function doInstall(PackageInterface $package, $path, $url)
    {
        $ref = $package->getSourceReference();
        $label = $this->getLabelFromSourceReference((string) $ref);

        $this->io->writeError('Cloning ' . $ref);
        $this->initPerforce($package, $path, $url);
        $this->perforce->setStream($ref);
        $this->perforce->p4Login();
        $this->perforce->writeP4ClientSpec();
        $this->perforce->connectClient();
        $this->perforce->syncCodeBase($label);
        $this->perforce->cleanupClientSpec();

        return \React\Promise\resolve();
    }

    /**
     * @param string $ref
     *
     * @return string|null
     */
    private function getLabelFromSourceReference($ref)
    {
        $pos = strpos($ref, '@');
        if (false !== $pos) {
            return substr($ref, $pos + 1);
        }

        return null;
    }

    /**
     * @param string $path
     * @param string $url
     *
     * @return void
     */
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

    /**
     * @return array<string, mixed>
     */
    private function getRepoConfig(VcsRepository $repository)
    {
        return $repository->getRepoConfig();
    }

    /**
     * @inheritDoc
     */
    protected function doUpdate(PackageInterface $initial, PackageInterface $target, $path, $url)
    {
        return $this->doInstall($target, $path, $url);
    }

    /**
     * @inheritDoc
     */
    public function getLocalChanges(PackageInterface $package, $path)
    {
        $this->io->writeError('Perforce driver does not check for local changes before overriding');

        return null;
    }

    /**
     * @inheritDoc
     */
    protected function getCommitLogs($fromReference, $toReference, $path)
    {
        return $this->perforce->getCommitLogs($fromReference, $toReference);
    }

    /**
     * @return void
     */
    public function setPerforce(Perforce $perforce)
    {
        $this->perforce = $perforce;
    }

    /**
     * @inheritDoc
     */
    protected function hasMetadataRepository($path)
    {
        return true;
    }
}
