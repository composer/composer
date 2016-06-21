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
class CompositeRepository extends BaseRepository
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
        $this->repositories = array();
        foreach ($repositories as $repo) {
            $this->addRepository($repo);
        }
    }

    /**
     * Returns all the wrapped repositories
     *
     * @return array
     */
    public function getRepositories()
    {
        return $this->repositories;
    }

    /**
     * {@inheritdoc}
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
     * {@inheritdoc}
     */
    public function findPackage($name, $constraint)
    {
        foreach ($this->repositories as $repository) {
            /* @var $repository RepositoryInterface */
            $package = $repository->findPackage($name, $constraint);
            if (null !== $package) {
                return $package;
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function findPackages($name, $constraint = null)
    {
        $packages = array();
        foreach ($this->repositories as $repository) {
            /* @var $repository RepositoryInterface */
            $packages[] = $repository->findPackages($name, $constraint);
        }

        return $packages ? call_user_func_array('array_merge', $packages) : array();
    }

    /**
     * {@inheritdoc}
     */
    public function search($query, $mode = 0, $type = null)
    {
        $matches = array();
        foreach ($this->repositories as $repository) {
            /* @var $repository RepositoryInterface */
            $matches[] = $repository->search($query, $mode, $type);
        }

        return $matches ? call_user_func_array('array_merge', $matches) : array();
    }

    /**
     * {@inheritdoc}
     */
    public function getPackages()
    {
        $packages = array();
        foreach ($this->repositories as $repository) {
            /* @var $repository RepositoryInterface */
            $packages[] = $repository->getPackages();
        }

        return $packages ? call_user_func_array('array_merge', $packages) : array();
    }

    /**
     * {@inheritdoc}
     */
    public function removePackage(PackageInterface $package)
    {
        foreach ($this->repositories as $repository) {
            /* @var $repository RepositoryInterface */
            $repository->removePackage($package);
        }
    }

    /**
     * {@inheritdoc}
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

    /**
     * Add a repository.
     * @param RepositoryInterface $repository
     */
    public function addRepository(RepositoryInterface $repository)
    {
        if ($repository instanceof self) {
            foreach ($repository->getRepositories() as $repo) {
                $this->addRepository($repo);
            }
        } else {
            $this->repositories[] = $repository;
        }
    }
}
