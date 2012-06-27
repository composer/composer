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
use Composer\Installer\InstallationManager;
use Composer\Repository\RepositoryManager;
use Composer\Util\ProcessExecutor;
use Composer\Package\AliasPackage;

/**
 * Reads/writes project lockfile (composer.lock).
 *
 * @author Konstantin Kudryashiv <ever.zet@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Locker
{
    private $lockFile;
    private $repositoryManager;
    private $installationManager;
    private $hash;
    private $lockDataCache;

    /**
     * Initializes packages locker.
     *
     * @param JsonFile            $lockFile            lockfile loader
     * @param RepositoryManager   $repositoryManager   repository manager instance
     * @param InstallationManager $installationManager installation manager instance
     * @param string              $hash                unique hash of the current composer configuration
     */
    public function __construct(JsonFile $lockFile, RepositoryManager $repositoryManager, InstallationManager $installationManager, $hash)
    {
        $this->lockFile          = $lockFile;
        $this->repositoryManager = $repositoryManager;
        $this->installationManager = $installationManager;
        $this->hash = $hash;
    }

    /**
     * Checks whether locker were been locked (lockfile found).
     *
     * @param  bool $dev true to check if dev packages are locked
     * @return bool
     */
    public function isLocked($dev = false)
    {
        if (!$this->lockFile->exists()) {
            return false;
        }

        $data = $this->getLockData();
        if ($dev) {
            return isset($data['packages-dev']);
        }

        return isset($data['packages']);
    }

    /**
     * Checks whether the lock file is still up to date with the current hash
     *
     * @return bool
     */
    public function isFresh()
    {
        $lock = $this->lockFile->read();

        return $this->hash === $lock['hash'];
    }

    /**
     * Searches and returns an array of locked packages, retrieved from registered repositories.
     *
     * @param  bool  $dev true to retrieve the locked dev packages
     * @return array
     */
    public function getLockedPackages($dev = false)
    {
        $lockData = $this->getLockData();
        $packages = array();

        $lockedPackages = $dev ? $lockData['packages-dev'] : $lockData['packages'];
        $repo = $dev ? $this->repositoryManager->getLocalDevRepository() : $this->repositoryManager->getLocalRepository();

        foreach ($lockedPackages as $info) {
            $resolvedVersion = !empty($info['alias-version']) ? $info['alias-version'] : $info['version'];

            // try to find the package in the local repo (best match)
            $package = $repo->findPackage($info['package'], $resolvedVersion);

            // try to find the package in any repo
            if (!$package) {
                $package = $this->repositoryManager->findPackage($info['package'], $resolvedVersion);
            }

            // try to find the package in any repo (second pass without alias + rebuild alias since it disappeared)
            if (!$package && !empty($info['alias-version'])) {
                $package = $this->repositoryManager->findPackage($info['package'], $info['version']);
                if ($package) {
                    $alias = new AliasPackage($package, $info['alias-version'], $info['alias-pretty-version']);
                    $package->getRepository()->addPackage($alias);
                    $package = $alias;
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

    public function getMinimumStability()
    {
        $lockData = $this->getLockData();

        // TODO BC change dev to stable end of june?
        return isset($lockData['minimum-stability']) ? $lockData['minimum-stability'] : 'dev';
    }

    public function getStabilityFlags()
    {
        $lockData = $this->getLockData();

        return isset($lockData['stability-flags']) ? $lockData['stability-flags'] : array();
    }

    public function getAliases()
    {
        $lockData = $this->getLockData();

        return isset($lockData['aliases']) ? $lockData['aliases'] : array();
    }

    public function getLockData()
    {
        if (null !== $this->lockDataCache) {
            return $this->lockDataCache;
        }

        if (!$this->lockFile->exists()) {
            throw new \LogicException('No lockfile found. Unable to read locked packages');
        }

        return $this->lockDataCache = $this->lockFile->read();
    }

    /**
     * Locks provided data into lockfile.
     *
     * @param array $packages array of packages
     * @param mixed $packages array of dev packages or null if installed without --dev
     * @param array $aliases  array of aliases
     *
     * @return bool
     */
    public function setLockData(array $packages, $devPackages, array $aliases, $minimumStability, array $stabilityFlags)
    {
        $lock = array(
            'hash' => $this->hash,
            'packages' => null,
            'packages-dev' => null,
            'aliases' => $aliases,
            'minimum-stability' => $minimumStability,
            'stability-flags' => $stabilityFlags,
        );

        $lock['packages'] = $this->lockPackages($packages);
        if (null !== $devPackages) {
            $lock['packages-dev'] = $this->lockPackages($devPackages);
        }

        if (!$this->isLocked() || $lock !== $this->getLockData()) {
            $this->lockFile->write($lock);
            $this->lockDataCache = null;

            return true;
        }

        return false;
    }

    private function lockPackages(array $packages)
    {
        $locked = array();

        foreach ($packages as $package) {
            $alias = null;

            if ($package instanceof AliasPackage) {
                $alias = $package;
                $package = $package->getAliasOf();
            }

            $name    = $package->getPrettyName();
            $version = $package->getVersion();

            if (!$name || !$version) {
                throw new \LogicException(sprintf(
                    'Package "%s" has no version or name and can not be locked', $package
                ));
            }

            $spec = array('package' => $name, 'version' => $version);

            if ($package->isDev() && !$alias) {
                $spec['source-reference'] = $package->getSourceReference();
                if ('git' === $package->getSourceType() && $path = $this->installationManager->getInstallPath($package)) {
                    $process = new ProcessExecutor();
                    if (0 === $process->execute('git log -n1 --pretty=%ct '.escapeshellarg($package->getSourceReference()), $output, $path)) {
                        $spec['commit-date'] = trim($output);
                    }
                }
            }

            if ($alias) {
                $spec['alias-pretty-version'] = $alias->getPrettyVersion();
                $spec['alias-version'] = $alias->getVersion();
            }

            $locked[] = $spec;
        }

        usort($locked, function ($a, $b) {
            $comparison = strcmp($a['package'], $b['package']);

            if (0 !== $comparison) {
                return $comparison;
            }

            // If it is the same package, compare the versions to make the order deterministic
            $aVersion = isset($a['alias-version']) ? $a['alias-version'] : $a['version'];
            $bVersion = isset($b['alias-version']) ? $b['alias-version'] : $b['version'];

            return strcmp($aVersion, $bVersion);
        });

        return $locked;
    }
}
