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

/**
 * DownloadManager with package cache.
 * All downloaded packages will be stored in cache.
 * 
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 */
class CachedDownloadManager extends DownloadManager
{
    /**
     * @var Storage\PackageStorageInterface
     */
    private $storage;

    /**
     * @param Storage\PackageStorageInterface $storage Package cache storage
     * @param bool                            $preferSource Prefer downloading source
     */
    public function __construct(Storage\PackageStorageInterface $storage, $preferSource = false)
    {
        $this->storage = $storage;
        parent::__construct($preferSource);
    }

    /**
     * Get storage
     *
     * @return Storage\PackageStorageInterface
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
        $this->storage->storePackage($package, $targetDir);
    }

    /**
     * {@inheritDoc}
     */
    public function update(PackageInterface $initial, PackageInterface $target, $targetDir)
    {
        parent::update($initial, $target, $targetDir);
        $this->storage->storePackage($target, $targetDir);
    }

}
