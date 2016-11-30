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

use Composer\IO\IOInterface;
use Composer\Config;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Package\PackageInterface;
use Composer\Util\RemoteFilesystem;

/**
 * Repositories manager.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
class RepositoryManager
{
    private $localRepository;
    private $repositories = array();
    private $repositoryClasses = array();
    private $io;
    private $config;
    private $eventDispatcher;
    private $rfs;

    public function __construct(IOInterface $io, Config $config, EventDispatcher $eventDispatcher = null, RemoteFilesystem $rfs = null)
    {
        $this->io = $io;
        $this->config = $config;
        $this->eventDispatcher = $eventDispatcher;
        $this->rfs = $rfs;
    }

    /**
     * Searches for a package by it's name and version in managed repositories.
     *
     * @param string                                                 $name       package name
     * @param string|\Composer\Semver\Constraint\ConstraintInterface $constraint package version or version constraint to match against
     *
     * @return PackageInterface|null
     */
    public function findPackage($name, $constraint)
    {
        foreach ($this->repositories as $repository) {
            if ($package = $repository->findPackage($name, $constraint)) {
                return $package;
            }
        }

        return null;
    }

    /**
     * Searches for all packages matching a name and optionally a version in managed repositories.
     *
     * @param string                                                 $name       package name
     * @param string|\Composer\Semver\Constraint\ConstraintInterface $constraint package version or version constraint to match against
     *
     * @return array
     */
    public function findPackages($name, $constraint)
    {
        $packages = array();

        foreach ($this->repositories as $repository) {
            $packages = array_merge($packages, $repository->findPackages($name, $constraint));
        }

        return $packages;
    }

    /**
     * Adds repository
     *
     * @param RepositoryInterface $repository repository instance
     */
    public function addRepository(RepositoryInterface $repository)
    {
        $this->repositories[] = $repository;
    }

    /**
     * Adds a repository to the beginning of the chain
     *
     * This is useful when injecting additional repositories that should trump Packagist, e.g. from a plugin.
     *
     * @param RepositoryInterface $repository repository instance
     */
    public function prependRepository(RepositoryInterface $repository)
    {
        array_unshift($this->repositories, $repository);
    }

    /**
     * Returns a new repository for a specific installation type.
     *
     * @param  string                    $type   repository type
     * @param  array                     $config repository configuration
     * @param  string                    $name   repository name
     * @throws \InvalidArgumentException if repository for provided type is not registered
     * @return RepositoryInterface
     */
    public function createRepository($type, $config, $name = null)
    {
        if (!isset($this->repositoryClasses[$type])) {
            throw new \InvalidArgumentException('Repository type is not registered: '.$type);
        }

        if (isset($config['packagist']) && false === $config['packagist']) {
            $this->io->writeError('<warning>Repository "'.$name.'" ('.json_encode($config).') has a packagist key which should be in its own repository definition</warning>');
        }

        $class = $this->repositoryClasses[$type];

        $reflMethod = new \ReflectionMethod($class, '__construct');
        $params = $reflMethod->getParameters();
        if (isset($params[4]) && $params[4]->getClass() && $params[4]->getClass()->getName() === 'Composer\Util\RemoteFilesystem') {
            return new $class($config, $this->io, $this->config, $this->eventDispatcher, $this->rfs);
        }

        return new $class($config, $this->io, $this->config, $this->eventDispatcher);
    }

    /**
     * Stores repository class for a specific installation type.
     *
     * @param string $type  installation type
     * @param string $class class name of the repo implementation
     */
    public function setRepositoryClass($type, $class)
    {
        $this->repositoryClasses[$type] = $class;
    }

    /**
     * Returns all repositories, except local one.
     *
     * @return array
     */
    public function getRepositories()
    {
        return $this->repositories;
    }

    /**
     * Sets local repository for the project.
     *
     * @param WritableRepositoryInterface $repository repository instance
     */
    public function setLocalRepository(WritableRepositoryInterface $repository)
    {
        $this->localRepository = $repository;
    }

    /**
     * Returns local repository for the project.
     *
     * @return WritableRepositoryInterface
     */
    public function getLocalRepository()
    {
        return $this->localRepository;
    }
}
