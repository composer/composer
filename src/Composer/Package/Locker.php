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

namespace Composer\Package;

use Composer\Json\JsonFile;
use Composer\Repository\RepositoryManager;

/**
 * Reads/writes project lockfile (composer.lock).
 *
 * @author Konstantin Kudryashiv <ever.zet@gmail.com>
 */
class Locker
{
    private $lockFile;
    private $repositoryManager;

    /**
     * Initializes packages locker.
     *
     * @param   JsonFile            $lockFile           lockfile loader
     * @param   RepositoryManager   $repositoryManager  repository manager instance
     */
    public function __construct(JsonFile $lockFile, RepositoryManager $repositoryManager)
    {
        $this->lockFile          = $lockFile;
        $this->repositoryManager = $repositoryManager;
    }

    /**
     * Checks whether locker were been locked (lockfile found).
     *
     * @return  Boolean
     */
    public function isLocked()
    {
        return $this->lockFile->exists();
    }

    /**
     * Searches and returns an array of locked packages, retrieved from registered repositories.
     *
     * @return  array
     */
    public function getLockedPackages()
    {
        if (!$this->isLocked()) {
            throw new \LogicException('No lockfile found. Unable to read locked packages');
        }

        $lockList = $this->lockFile->read();
        $packages = array();
        foreach ($lockList as $info) {
            $package = $this->repositoryManager->getLocalRepository()->findPackage($info['package'], $info['version']);

            if (!$package) {
                $package = $this->repositoryManager->findPackage($info['package'], $info['version']);
            }

            if (!$package) {
                throw new \LogicException(sprintf(
                    'Can not find "%s-%s" package in registered repositories',
                    $info['package'], $info['version']
                ));
            }

            $packages[] = $package;
        }

        return $packages;
    }

    public function getLockedPackage($packageName)
    {
        $packages = $this->getLockedPackages();

        foreach ($packages as $package) {
            if ($packageName === $package->getName()) {
                return $package;
            }
        }

        throw new \LogicException(sprintf(
            'Can not find "%s" package in registered repositories',
            $packageName
        ));
    }

    /**
     * Locks provided packages into lockfile.
     *
     * @param   array   $packages   array of packages
     */
    public function lockPackages(array $packages)
    {
        $hash = array();
        foreach ($packages as $package) {
            $name    = $package->getPrettyName();
            $version = $package->getPrettyVersion();

            if (!$name || !$version) {
                throw new \LogicException(sprintf(
                    'Package "%s" has no version or name and can not be locked', $package
                ));
            }

            $hash[] = array('package' => $name, 'version' => $version);
        }

        $this->lockFile->write($hash);
    }
}
