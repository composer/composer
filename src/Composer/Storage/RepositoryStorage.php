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

use Composer\Package\PackageInterface;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Repository\WritableRepositoryInterface;

/**
 * RepositoryStorage write packages to repository and stores them inside internal storage
 *
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 */
class RepositoryStorage implements StorageInterface
{
    private static $loader;
    private static $dumper;

    private $repository;
    private $internalStorage;

    /**
     * Constructor with repository and internal storage
     *
     * @param WritableRepositoryInterface $repository
     * @param StorageInterface            $internalStorage
     */
    public function __construct(WritableRepositoryInterface $repository, StorageInterface $internalStorage)
    {
        $this->repository = $repository;
        $this->internalStorage = $internalStorage;
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
     * Get internal storage
     *
     * @return StorageInterface
     */
    public function getInternalStorage()
    {
        return $this->internalStorage;
    }

    /**
     * {@inheritDoc}
     */
    public function storePackage(PackageInterface $package, $sourceDir)
    {
        $dist = $this->internalStorage->storePackage($package, $sourceDir);

        $packageCopy = self::convertPackage($package, $dist);

        // Remove old package from repository in a replace situation
        if ($this->repository->hasPackage($package)) {
            $this->repository->removePackage($package);
        }
        $this->repository->addPackage($packageCopy);
        $this->repository->write();

        return $dist;
    }

    /**
     * {@inheritDoc}
     */
    public function retrievePackage(PackageInterface $package)
    {
        if (!$this->hasPackage($package)) {
            return null;
        }

        return $this->internalStorage->retrievePackage($package);
    }

    /**
     * Check if package exists in the storage
     *
     * @param PackageInterface $package
     *
     * @return bool
     */
    public function hasPackage(PackageInterface $package)
    {
        return $this->repository->hasPackage($package) && $this->internalStorage->hasPackage($package);
    }

    /**
     * Creates package copy with overwritten distribution parameters
     *
     * @param PackageInterface    $package
     * @param PackageDistribution $dist
     *
     * @return PackageInterface
     */
    private static function convertPackage(PackageInterface $package, PackageDistribution $dist)
    {
        $loader = self::$loader ?: (self::$loader = new ArrayLoader());
        $dumper = self::$dumper ?: (self::$dumper = new ArrayDumper());

        $config = $dumper->dump($package);
        $config['dist'] = array(
            'type'   => $dist->getType(),
            'url'    => $dist->getUrl(),
            'shasum' => $dist->getSha1Checksum()
        );

        return $loader->load($config);
    }
}
