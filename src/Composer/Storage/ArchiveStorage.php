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

namespace Composer\Storage;

use Composer\Util\Archive\CompressorInterface;
use Composer\Package\PackageInterface;

/**
 * PackageStorageInterface local archive implementation
 * 
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 */
class ArchiveStorage implements StorageInterface
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
     * Constructor
     *
     * @param string              $storageDir Directory to store package archives
     * @param CompressorInterface $compressor Archive compressor instance
     */
    public function __construct($storageDir, CompressorInterface $compressor)
    {
        $this->storageDir = $storageDir;
        $this->compressor = $compressor;
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
     * {@inheritDoc}
     */
    public function storePackage(PackageInterface $package, $sourceDir)
    {
        $fileName = $this->packageFilename($package);

        $dir = dirname($fileName);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
            throw new \RuntimeException('Can not initialize directory structure in ' . $dir);
        }

        $this->compressor->compressDir($sourceDir, $fileName);

        return $this->createDistribution($fileName);
    }

    /**
     * {@inheritDoc}
     */
    public function retrievePackage(PackageInterface $package)
    {
        $fileName = $this->packageFilename($package);

        return file_exists($fileName) ? $this->createDistribution($fileName) : null;
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
     * Create a distribution object for file
     *
     * @param string $fileName
     *
     * @return PackageDistribution
     */
    private function createDistribution($fileName)
    {
        return new PackageDistribution($this->compressor->getArchiveType(), $fileName, @sha1_file($fileName));
    }
}
