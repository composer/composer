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

namespace Composer\Command;

use Composer\Repository\PlatformRepository;
use Composer\Package\PackageInterface;
use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Base class for Composer commands
 *
 * @author Ryan Weaver <ryan@knplabs.com>
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 * @author Robert Schönthal <seroscho@googlemail.com>
 */
abstract class Command extends BaseCommand
{
    /**
     * @return Composer\Composer
     */
    protected function getComposer()
    {
        return $this->getApplication()->getComposer();
    }
    
    /**
     * @return array[Composer\Repository\RepositoryInterface]
     */
    public function getRepositories()
    {
        return $this->getComposer()->getRepositoryManager()->getRepositories();
    }
    
    /**
     * @return Composer\Repository\RepositoryInterface
     */
    public function getLocalRepository()
    {
        return $this->getComposer()->getRepositoryManager()->getLocalRepository();
    }
    
    /**
     * @return Composer\Repository\PlatformRepository 
     */
    public function getInstalledRepository()
    {
        return new PlatformRepository($this->getLocalRepository());
    }
    
    /**
     * finds a package by name and version if provided
     *
     * @param InputInterface $input
     * @return PackageInterface|null
     * @throws \InvalidArgumentException
     */
    public function getPackage(InputInterface $input, OutputInterface $output)
    {
        $name = $input->getArgument('package');
        $version = $input->getArgument('version');
        
        // we have a name and a version so we can use ::findPackage
        if ($version) {
            return $this->findPackage($name, $version);
        }

        // check if we have a local installation so we can grab the right package/version
        if ($package = $this->getLocalPackage($name)) {
           return $package; 
        }
        
        // we only have a name, so search for the highest version of the given package
        return $this->getHighestVersion($name);
    }
    
    /**
     * finds a package by name and version
     * 
     * @param string $name
     * @param string $version 
     * @return PackageInterface|null
     */
    public function findPackage($name, $version)
    {
        return $this->getComposer()->getRepositoryManager()->findPackage($name, $version);
    }
    
    /**
     * finds a local package by name
     * 
     * @param string $name
     * @return PackageInterface|null
     */
    protected function getLocalPackage($name)
    {
        $localRepo = $this->getLocalRepository();
        foreach ($localRepo->getPackages() as $package) {
            if ($package->getName() === $name) {
                return $package;
            }
        }
    }
    
    /**
     * finds the highest available version of a package
     * 
     * @param string $name
     * @return PackageInterface|null 
     */
    protected function getHighestVersion($name)
    {
        $highestVersion = null;
        $repos = array_merge(array($this->getLocalRepository()), $this->getRepositories());
        foreach ($repos as $repository) {
            foreach ($repository->findPackagesByName($name) as $package) {
                if (null === $highestVersion || version_compare($package->getVersion(), $highestVersion->getVersion(), '>=')) {
                    $highestVersion = $package;
                }
            }
        }
        
        return $highestVersion;
    }    
}