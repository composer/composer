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

namespace Composer\Downloader\Storage;

use Composer\Downloader\Util\Archive\CompressorInterface;
use Composer\Package\MemoryPackage;
use Composer\Package\PackageInterface;
use Composer\Repository\WritableRepositoryInterface;

/**
 * PackageStorageInterface local archive implementation
 * 
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 */
class ArchiveStorage implements PackageStorageInterface
{
    /**
     * @var string
     */
    private $storageDir;
    /**
     * @var CompressorInterface
     */
    private $compressor;
    /**
     * @var WritableRepositoryInterface
     */
    private $repository;

    /**
     * Constructor
     *
     * @param string                      $storageDir Directory to store package archives
     * @param CompressorInterface         $compressor Archive compressor instance
     * @param WritableRepositoryInterface $repository Writable repository to store package information
     */
    public function __construct($storageDir, CompressorInterface $compressor, WritableRepositoryInterface $repository)
    {
        $this->storageDir = $storageDir;
        $this->compressor = $compressor;
        $this->repository = $repository;
    }

    /**
     * Get storage directory
     *
     * @return string
     */
    public function getStorageDir()
    {
        return $this->storageDir;
    }

    /**
     * Get compressor
     *
     * @return CompressorInterface
     */
    public function getCompressor()
    {
        return $this->compressor;
    }

    /**
     * Get repository
     *
     * @return WritableRepositoryInterface
     */
    public function getRepository()
    {
        return $this->repository;
    }

    /**
     * {@inheritDoc}
     */
    public function storePackage(PackageInterface $package, $targetDir)
    {
        $storedPackage = $this->createStoredPackage($package);
        // If package archive is not exist, add it
        if (!file_exists($storedPackage->getDistUrl())) {
            $this->compressor->compressDir($targetDir, $storedPackage->getDistUrl());
        }
        $storedPackage->setDistSha1Checksum(sha1_file($storedPackage->getDistUrl()));

        // TODO: may be need to catch and rethrow RuntimeException
        $this->repository->addPackage($package);
        // Don't remove file if we can't add it to repository. Package can be added next time

        return $storedPackage;
    }

    /**
     * Get stored package filename
     *
     * @param PackageInterface $package Original package
     *
     * @return string Filename
     */
    private function packageFilename(PackageInterface $package)
    {
        return sprintf('%s/%s.%s', $this->storageDir, $package->getUniqueName(), $this->compressor->getArchiveType());
    }

    /**
     * Create package copy for storage
     *
     * @param PackageInterface $package Original package
     *
     * @return MemoryPackage
     */
    private function createStoredPackage(PackageInterface $package)
    {
        $package = MemoryPackage::fromPackage($package);
        $package->setDistType($this->compressor->getArchiveType());
        $package->setDistUrl($this->packageFilename($package));
        $package->setDistReference('');

        return $package;
    }
}
