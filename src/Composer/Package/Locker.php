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
use Composer\Package\AliasPackage;

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
    private $lockDataCache;

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
            $resolvedVersion = !empty($info['alias']) ? $info['alias'] : $info['version'];
            $package = $this->repositoryManager->getLocalRepository()->findPackage($info['package'], $resolvedVersion);

            if (!$package) {
                $package = $this->repositoryManager->findPackage($info['package'], $info['version']);
                if ($package && !empty($info['alias'])) {
                    $package = new AliasPackage($package, $info['alias'], $info['alias']);
                }
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

    public function getAliases()
    {
        $lockList = $this->getLockData();
        return isset($lockList['aliases']) ? $lockList['aliases'] : array();
    }

    public function getLockData()
    {
        if (!$this->isLocked()) {
            throw new \LogicException('No lockfile found. Unable to read locked packages');
        }

        if (null !== $this->lockDataCache) {
            return $this->lockDataCache;
        }

        return $this->lockDataCache = $this->lockFile->read();
    }

    /**
     * Locks provided data into lockfile.
     *
     * @param array $packages array of packages
     * @param array $aliases array of aliases
     *
     * @return Boolean
     */
    public function setLockData(array $packages, array $aliases)
    {
        $lock = array(
            'hash' => $this->hash,
            'packages' => array(),
            'aliases' => $aliases,
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

            if ($package->getAlias() && $package->isInstalledAsAlias()) {
                $spec['alias'] = $package->getAlias();
            }

            $lock['packages'][] = $spec;
        }

        usort($lock['packages'], function ($a, $b) {
            return strcmp($a['package'], $b['package']);
        });

        if (!$this->isLocked() || $lock !== $this->getLockData()) {
            $this->lockFile->write($lock);
            $this->lockDataCache = null;

            return true;
        }

        return false;
    }
}
