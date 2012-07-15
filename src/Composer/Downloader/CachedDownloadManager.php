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
use Composer\Storage\StorageInterface;
use Composer\Util\Filesystem;

/**
 * DownloadManager with package cache.
 * All downloaded packages will be stored in cache.
 * 
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 */
class CachedDownloadManager extends DownloadManager
{
    /**
     * @var StorageInterface
     */
    private $storage;

    /**
     * @param StorageInterface $storage      Package cache storage
     * @param bool             $preferSource Prefer downloading source
     * @param Filesystem|null  $filesystem   Custom Filesystem object
     */
    public function __construct(StorageInterface $storage, $preferSource = false, Filesystem $filesystem = null)
    {
        $this->storage = $storage;
        parent::__construct($preferSource, $filesystem);
    }

    /**
     * Get storage
     *
     * @return StorageInterface
     */
    public function getStorage()
    {
        return $this->storage;
    }

    /**
     * {@inheritDoc}
     */
    public function download(PackageInterface $package, $targetDir, $preferSource = null)
    {
        parent::download($package, $targetDir, $preferSource);

        $this->cachePackage($package, $targetDir);
    }

    /**
     * {@inheritDoc}
     */
    public function update(PackageInterface $initial, PackageInterface $target, $targetDir)
    {
        parent::update($initial, $target, $targetDir);

        $this->cachePackage($target, $targetDir);
    }

    /**
     * Cache package into storage if necessary
     *
     * @param PackageInterface $package
     * @param string           $targetDir
     */
    protected function cachePackage(PackageInterface $package, $targetDir)
    {
        if ($package->getInstallationSource() === 'dist' && !$this->storage->hasPackage($package)) {
            $this->storage->storePackage($package, $targetDir);
        }
    }
}
