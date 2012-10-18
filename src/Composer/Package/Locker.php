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
use Composer\Repository\ArrayRepository;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\Loader\ArrayLoader;

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
    private $loader;
    private $dumper;
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
        $this->loader = new ArrayLoader();
        $this->dumper = new ArrayDumper();
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
     * Checks whether the lock file is in the new complete format or not
     *
     * @param  bool $dev true to check in dev mode
     * @return bool
     */
    public function isCompleteFormat($dev)
    {
        $lockData = $this->getLockData();
        $lockedPackages = $dev ? $lockData['packages-dev'] : $lockData['packages'];

        if (empty($lockedPackages) || isset($lockedPackages[0]['name'])) {
            return true;
        }

        return false;
    }

    /**
     * Searches and returns an array of locked packages, retrieved from registered repositories.
     *
     * @param  bool                                     $dev true to retrieve the locked dev packages
     * @return \Composer\Repository\RepositoryInterface
     */
    public function getLockedRepository($dev = false)
    {
        $lockData = $this->getLockData();
        $packages = new ArrayRepository();

        $lockedPackages = $dev ? $lockData['packages-dev'] : $lockData['packages'];

        if (empty($lockedPackages)) {
            return $packages;
        }

        if (isset($lockedPackages[0]['name'])) {
            foreach ($lockedPackages as $info) {
                $packages->addPackage($this->loader->load($info));
            }

            return $packages;
        }

        // legacy lock file support
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
                    $package->setAlias($info['alias-version']);
                    $package->setPrettyAlias($info['alias-pretty-version']);
                }
            }

            if (!$package) {
                throw new \LogicException(sprintf(
                    'Can not find "%s-%s" package in registered repositories',
                    $info['package'], $info['version']
                ));
            }

            $package = clone $package;
            if (!empty($info['time'])) {
                $package->setReleaseDate($info['time']);
            }
            if (!empty($info['source-reference'])) {
                $package->setSourceReference($info['source-reference']);
                if (is_callable($package, 'setDistReference')) {
                    $package->setDistReference($info['source-reference']);
                }
            }

            $packages->addPackage($package);
        }

        return $packages;
    }

    public function getMinimumStability()
    {
        $lockData = $this->getLockData();

        return isset($lockData['minimum-stability']) ? $lockData['minimum-stability'] : 'stable';
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
            'aliases' => array(),
            'minimum-stability' => $minimumStability,
            'stability-flags' => $stabilityFlags,
        );

        foreach ($aliases as $package => $versions) {
            foreach ($versions as $version => $alias) {
                $lock['aliases'][] = array(
                    'alias' => $alias['alias'],
                    'alias_normalized' => $alias['alias_normalized'],
                    'version' => $version,
                    'package' => $package,
                );
            }
        }

        $lock['packages'] = $this->lockPackages($packages);
        if (null !== $devPackages) {
            $lock['packages-dev'] = $this->lockPackages($devPackages);
        }

        if (empty($lock['packages']) && empty($lock['packages-dev'])) {
            if ($this->lockFile->exists()) {
                unlink($this->lockFile->getPath());
            }

            return false;
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
            if ($package instanceof AliasPackage) {
                continue;
            }

            $name    = $package->getPrettyName();
            $version = $package->getPrettyVersion();

            if (!$name || !$version) {
                throw new \LogicException(sprintf(
                    'Package "%s" has no version or name and can not be locked', $package
                ));
            }

            $spec = $this->dumper->dump($package);
            unset($spec['version_normalized']);

            if ($package->isDev()) {
                if ('git' === $package->getSourceType() && $path = $this->installationManager->getInstallPath($package)) {
                    $sourceRef = $package->getSourceReference() ?: $package->getDistReference();
                    $process = new ProcessExecutor();
                    if (0 === $process->execute('git log -n1 --pretty=%ct '.escapeshellarg($sourceRef), $output, $path)) {
                        $spec['time'] = trim($output);
                    }
                }
            }

            $locked[] = $spec;
        }

        usort($locked, function ($a, $b) {
            $comparison = strcmp($a['name'], $b['name']);

            if (0 !== $comparison) {
                return $comparison;
            }

            // If it is the same package, compare the versions to make the order deterministic
            return strcmp($a['version'], $b['version']);
        });

        return $locked;
    }
}
