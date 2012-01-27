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

namespace Composer\Repository;

use Composer\Package\PackageInterface;

/**
 * Composite repository.
 *
 * @author Beau Simensen <beau@dflydev.com>
 */
class CompositeRepository implements RepositoryInterface
{

    /**
     * List of repositories
     * @var array
     */
    private $repositories;

    /**
     * Constructor
     * @param array $repositories
     */
    public function __construct(array $repositories)
    {
        $this->repositories = $repositories;
    }

    /**
     * (non-PHPdoc)
     * @see Composer\Repository.RepositoryInterface::hasPackage()
     */
    public function hasPackage(PackageInterface $package)
    {
        foreach ($this->repositories as $repository) {
            /* @var $repository RepositoryInterface */
            if ($repository->hasPackage($package)) {
                return true;
            }
        }
        return false;
    }

    /**
     * (non-PHPdoc)
     * @see Composer\Repository.RepositoryInterface::findPackage()
     */
    public function findPackage($name, $version) {
        foreach ($this->repositories as $repository) {
            /* @var $repository RepositoryInterface */
            $package = $repository->findPackage($name, $version);
            if (null !== $package) {
                return $package;
            }
        }
        return null;
    }

    /**
     * (non-PHPdoc)
     * @see Composer\Repository.RepositoryInterface::findPackagesByName()
     */
    public function findPackagesByName($name)
    {
        $packages = array();
        foreach ($this->repositories as $repository) {
            /* @var $repository RepositoryInterface */
            $packages[] = $repository->findPackagesByName($name);
        }
        return call_user_func_array('array_merge', $packages);
    }

    /**
     * (non-PHPdoc)
     * @see Composer\Repository.RepositoryInterface::getPackages()
     */
    public function getPackages()
    {
        $packages = array();
        foreach ($this->repositories as $repository) {
            /* @var $repository RepositoryInterface */
            $packages[] = $repository->getPackages();
        }
        return call_user_func_array('array_merge', $packages);
    }

    /**
     * Add a repository.
     * @param RepositoryInterface $repository
     */
    public function addRepository(RepositoryInterface $repository)
    {
        $this->repositories[] = $repository;
    }

    /**
     * (non-PHPdoc)
     * @see Countable::count()
     */
    public function count()
    {
        $total = 0;
        foreach ($this->repositories as $repository) {
            /* @var $repository RepositoryInterface */
            $total += $repository->count();
        }
        return $total;
    }
}
