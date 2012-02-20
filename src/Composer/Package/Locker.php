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
    private $hash;

    /**
     * Initializes packages locker.
     *
     * @param JsonFile            $lockFile           lockfile loader
     * @param RepositoryManager   $repositoryManager  repository manager instance
     * @param string              $hash               unique hash of the current composer configuration
     */
    public function __construct(JsonFile $lockFile, RepositoryManager $repositoryManager, $hash)
    {
        $this->lockFile          = $lockFile;
        $this->repositoryManager = $repositoryManager;
        $this->hash = $hash;
    }

    /**
     * Checks whether locker were been locked (lockfile found).
     *
     * @return Boolean
     */
    public function isLocked()
    {
        return $this->lockFile->exists();
    }

    /**
     * Checks whether the lock file is still up to date with the current hash
     *
     * @return Boolean
     */
    public function isFresh()
    {
        $lock = $this->lockFile->read();

        return $this->hash === $lock['hash'];
    }

    /**
     * Searches and returns an array of locked packages, retrieved from registered repositories.
     *
     * @return array
     */
    public function getLockedPackages()
    {
        $lockList = $this->getLockData();
        $packages = array();
        foreach ($lockList['packages'] as $info) {
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

    public function getLockData()
    {
        if (!$this->isLocked()) {
            throw new \LogicException('No lockfile found. Unable to read locked packages');
        }

        return $this->lockFile->read();
    }

    /**
     * Locks provided packages into lockfile.
     *
     * @param array $packages array of packages
     */
    public function lockPackages(array $packages)
    {
        $lock = array(
            'hash' => $this->hash,
            'packages' => array(),
        );
        foreach ($packages as $package) {
            $name    = $package->getPrettyName();
            $version = $package->getPrettyVersion();

            if (!$name || !$version) {
                throw new \LogicException(sprintf(
                    'Package "%s" has no version or name and can not be locked', $package
                ));
            }

            $spec = array('package' => $name, 'version' => $version);

            if ($package->isDev()) {
                $spec['source-reference'] = $package->getSourceReference();
            }

            $lock['packages'][] = $spec;
        }
        usort($lock['packages'], function ($a, $b) {
            return strcmp($a['package'], $b['package']);
        });

        $this->lockFile->write($lock);
    }
}
