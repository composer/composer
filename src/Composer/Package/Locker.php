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
use Composer\Repository\ArrayRepository;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Version\VersionParser;
use Composer\Util\Git as GitUtil;
use Composer\IO\IOInterface;

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
    private $process;
    private $lockDataCache;

    /**
     * Initializes packages locker.
     *
     * @param IOInterface         $io
     * @param JsonFile            $lockFile            lockfile loader
     * @param RepositoryManager   $repositoryManager   repository manager instance
     * @param InstallationManager $installationManager installation manager instance
     * @param string              $hash                unique hash of the current composer configuration
     */
    public function __construct(IOInterface $io, JsonFile $lockFile, RepositoryManager $repositoryManager, InstallationManager $installationManager, $hash)
    {
        $this->lockFile = $lockFile;
        $this->repositoryManager = $repositoryManager;
        $this->installationManager = $installationManager;
        $this->hash = $hash;
        $this->loader = new ArrayLoader(null, true);
        $this->dumper = new ArrayDumper();
        $this->process = new ProcessExecutor($io);
    }

    /**
     * Checks whether locker were been locked (lockfile found).
     *
     * @return bool
     */
    public function isLocked()
    {
        if (!$this->lockFile->exists()) {
            return false;
        }

        $data = $this->getLockData();

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
     * @param  bool                                     $withDevReqs true to retrieve the locked dev packages
     * @throws \RuntimeException
     * @return \Composer\Repository\RepositoryInterface
     */
    public function getLockedRepository($withDevReqs = false)
    {
        $lockData = $this->getLockData();
        $packages = new ArrayRepository();

        $lockedPackages = $lockData['packages'];
        if ($withDevReqs) {
            if (isset($lockData['packages-dev'])) {
                $lockedPackages = array_merge($lockedPackages, $lockData['packages-dev']);
            } else {
                throw new \RuntimeException('The lock file does not contain require-dev information, run install with the --no-dev option or run update to install those packages.');
            }
        }

        if (empty($lockedPackages)) {
            return $packages;
        }

        if (isset($lockedPackages[0]['name'])) {
            foreach ($lockedPackages as $info) {
                $packages->addPackage($this->loader->load($info));
            }

            return $packages;
        }

        throw new \RuntimeException('Your composer.lock was created before 2012-09-15, and is not supported anymore. Run "composer update" to generate a new one.');
    }

    /**
     * Returns the platform requirements stored in the lock file
     *
     * @param  bool                     $withDevReqs if true, the platform requirements from the require-dev block are also returned
     * @return \Composer\Package\Link[]
     */
    public function getPlatformRequirements($withDevReqs = false)
    {
        $lockData = $this->getLockData();
        $versionParser = new VersionParser();
        $requirements = array();

        if (!empty($lockData['platform'])) {
            $requirements = $versionParser->parseLinks(
                '__ROOT__',
                '1.0.0',
                'requires',
                isset($lockData['platform']) ? $lockData['platform'] : array()
            );
        }

        if ($withDevReqs && !empty($lockData['platform-dev'])) {
            $devRequirements = $versionParser->parseLinks(
                '__ROOT__',
                '1.0.0',
                'requires',
                isset($lockData['platform-dev']) ? $lockData['platform-dev'] : array()
            );

            $requirements = array_merge($requirements, $devRequirements);
        }

        return $requirements;
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

    public function getPreferStable()
    {
        $lockData = $this->getLockData();

        // return null if not set to allow caller logic to choose the
        // right behavior since old lock files have no prefer-stable
        return isset($lockData['prefer-stable']) ? $lockData['prefer-stable'] : null;
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
     * @param array  $packages         array of packages
     * @param mixed  $devPackages      array of dev packages or null if installed without --dev
     * @param array  $platformReqs     array of package name => constraint for required platform packages
     * @param mixed  $platformDevReqs  array of package name => constraint for dev-required platform packages
     * @param array  $aliases          array of aliases
     * @param string $minimumStability
     * @param array  $stabilityFlags
     * @param bool   $preferStable
     *
     * @return bool
     */
    public function setLockData(array $packages, $devPackages, array $platformReqs, $platformDevReqs, array $aliases, $minimumStability, array $stabilityFlags, $preferStable)
    {
        $lock = array(
            '_readme' => array('This file locks the dependencies of your project to a known state',
                               'Read more about it at http://getcomposer.org/doc/01-basic-usage.md#composer-lock-the-lock-file',
                               'This file is @gener'.'ated automatically'),
            'hash' => $this->hash,
            'packages' => null,
            'packages-dev' => null,
            'aliases' => array(),
            'minimum-stability' => $minimumStability,
            'stability-flags' => $stabilityFlags,
            'prefer-stable' => $preferStable,
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

        $lock['platform'] = $platformReqs;
        $lock['platform-dev'] = $platformDevReqs;

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

            // always move time to the end of the package definition
            $time = isset($spec['time']) ? $spec['time'] : null;
            unset($spec['time']);
            if ($package->isDev() && $package->getInstallationSource() === 'source') {
                // use the exact commit time of the current reference if it's a dev package
                $time = $this->getPackageTime($package) ?: $time;
            }
            if (null !== $time) {
                $spec['time'] = $time;
            }

            unset($spec['installation-source']);

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

    /**
     * Returns the packages's datetime for its source reference.
     *
     * @param  PackageInterface $package The package to scan.
     * @return string|null      The formatted datetime or null if none was found.
     */
    private function getPackageTime(PackageInterface $package)
    {
        if (!function_exists('proc_open')) {
            return null;
        }

        $path = realpath($this->installationManager->getInstallPath($package));
        $sourceType = $package->getSourceType();
        $datetime = null;

        if ($path && in_array($sourceType, array('git', 'hg'))) {
            $sourceRef = $package->getSourceReference() ?: $package->getDistReference();
            switch ($sourceType) {
                case 'git':
                    GitUtil::cleanEnv();

                    if (0 === $this->process->execute('git log -n1 --pretty=%ct '.ProcessExecutor::escape($sourceRef), $output, $path) && preg_match('{^\s*\d+\s*$}', $output)) {
                        $datetime = new \DateTime('@'.trim($output), new \DateTimeZone('UTC'));
                    }
                    break;

                case 'hg':
                    if (0 === $this->process->execute('hg log --template "{date|hgdate}" -r '.ProcessExecutor::escape($sourceRef), $output, $path) && preg_match('{^\s*(\d+)\s*}', $output, $match)) {
                        $datetime = new \DateTime('@'.$match[1], new \DateTimeZone('UTC'));
                    }
                    break;
            }
        }

        return $datetime ? $datetime->format('Y-m-d H:i:s') : null;
    }
}
