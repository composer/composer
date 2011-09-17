<?php

namespace Composer\Package;

use Composer\Package\PackageInterface;

class Manager
{
    private $composer;

    public function __construct(Composer $composer)
    {
        $this->composer = $composer;
    }

    public function isInstalled(PackageInterface $package)
    {
        $installer   = $this->composer->getInstaller($package->getType());
        $downloader  = $this->getDownloaderForPackage($package);
        $packageType = $this->getTypeForPackage($package);

        return $installer->isInstalled($package, $downloader, $packageType);
    }

    public function install(PackageInterface $package)
    {
        $installer   = $this->composer->getInstaller($package->getType());
        $downloader  = $this->getDownloaderForPackage($package);
        $packageType = $this->getTypeForPackage($package);

        if (!$installer->install($package, $downloader, $packageType)) {
            throw new \LogicException($package->getName().' could not be installed.');
        }
    }

    public function update(PackageInterface $package)
    {
        $installer   = $this->composer->getInstaller($package->getType());
        $downloader  = $this->getDownloaderForPackage($package);
        $packageType = $this->getTypeForPackage($package);

        if (!$installer->update($package, $downloader, $packageType)) {
            throw new \LogicException($package->getName().' could not be updated.');
        }
    }

    public function remove(PackageInterface $package)
    {
        $installer   = $this->composer->getInstaller($package->getType());
        $downloader  = $this->getDownloaderForPackage($package);
        $packageType = $this->getTypeForPackage($package);

        if (!$installer->remove($package, $downloader, $packageType)) {
            throw new \LogicException($package->getName().' could not be removed.');
        }
    }

    private function getDownloaderForPackage(PackageInterface $package)
    {
        if ($package->getDistType()) {
            $downloader = $this->composer->getDownloader($package->getDistType);
        } elseif ($package->getSourceType()) {
            $downloader = $this->copmoser->getDownloader($package->getSourceType());
        } else {
            throw new \UnexpectedValueException(
                'Package '.$package->getName().' has no source or dist URL.'
            );
        }

        return $downloader;
    }

    private function getTypeForPackage(PackageInterface $package)
    {
        if ($package->getDistType()) {
            $type = 'dist';
        } elseif ($package->getSourceType()) {
            $type = 'source';
        } else {
            throw new \UnexpectedValueException(
                'Package '.$package->getName().' has no source or dist URL.'
            );
        }

        return $type;
    }
}
