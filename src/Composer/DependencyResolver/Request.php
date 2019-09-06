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

namespace Composer\DependencyResolver;

use Composer\Package\Package;
use Composer\Package\PackageInterface;
use Composer\Package\RootAliasPackage;
use Composer\Repository\RepositoryInterface;
use Composer\Semver\Constraint\ConstraintInterface;

/**
 * @author Nils Adermann <naderman@naderman.de>
 */
class Request
{
    protected $lockedRepository;
    protected $jobs = array();
    protected $fixedPackages = array();
    protected $unlockables = array();

    public function __construct(RepositoryInterface $lockedRepository = null)
    {
        $this->lockedRepository = $lockedRepository;
    }

    public function install($packageName, ConstraintInterface $constraint = null)
    {
        $this->addJob($packageName, 'install', $constraint);
    }

    public function remove($packageName, ConstraintInterface $constraint = null)
    {
        $this->addJob($packageName, 'remove', $constraint);
    }

    /**
     * Mark an existing package as being installed and having to remain installed
     */
    public function fixPackage(PackageInterface $package, $lockable = true)
    {
        if ($package instanceof RootAliasPackage) {
            $package = $package->getAliasOf();
        }

        $this->fixedPackages[spl_object_hash($package)] = $package;

        if (!$lockable) {
            $this->unlockables[] = $package;
        }
    }

    protected function addJob($packageName, $cmd, ConstraintInterface $constraint = null)
    {
        $packageName = strtolower($packageName);

        $this->jobs[] = array(
            'cmd' => $cmd,
            'packageName' => $packageName,
            'constraint' => $constraint,
        );
    }

    public function getJobs()
    {
        return $this->jobs;
    }

    public function getFixedPackages()
    {
        return $this->fixedPackages;
    }

    public function isFixedPackage(PackageInterface $package)
    {
        return isset($this->fixedPackages[spl_object_hash($package)]);
    }

    public function getPresentMap()
    {
        $presentMap = array();

        if ($this->lockedRepository) {
            foreach ($this->lockedRepository as $package) {
                $presentMap[$package->id] = $package;
            }
        }

        foreach ($this->fixedPackages as $package) {
            $presentMap[$package->id] = $package;
        }

        return $presentMap;
    }

    public function getUnlockableMap()
    {
        $unlockableMap = array();

        foreach ($this->unlockables as $package) {
            $unlockableMap[$package->id] = $package;
        }

        return $unlockableMap;
    }

    public function getLockMap()
    {
    }
}
