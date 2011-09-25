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

/**
 * Repositories manager.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class RepositoryManager
{
    private $localRepository;
    private $repositories = array();

    /**
     * Sets repository with specific name.
     *
     * @param   string              $type       repository name
     * @param   RepositoryInterface $repository repository instance
     */
    public function setRepository($type, RepositoryInterface $repository)
    {
        $this->repositories[$type] = $repository;
    }

    /**
     * Returns repository for a specific installation type.
     *
     * @param   string  $type   installation type
     *
     * @return  RepositoryInterface
     *
     * @throws  InvalidArgumentException     if repository for provided type is not registeterd
     */
    public function getRepository($type)
    {
        if (!isset($this->repositories[$type])) {
            throw new \InvalidArgumentException('Repository is not registered: '.$type);
        }

        return $this->repositories[$type];
    }

    /**
     * Returns all repositories, except local one.
     *
     * @return  array
     */
    public function getRepositories()
    {
        return $this->repositories;
    }

    /**
     * Sets local repository for the project.
     *
     * @param   RepositoryInterface $repository repository instance
     */
    public function setLocalRepository(RepositoryInterface $repository)
    {
        $this->localRepository = $repository;
    }

    /**
     * Returns local repository for the project.
     *
     * @return  RepositoryInterface
     */
    public function getLocalRepository()
    {
        return $this->localRepository;
    }
}
