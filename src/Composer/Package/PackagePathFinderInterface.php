<?php


namespace Composer\Package;


interface PackagePathFinderInterface
{
    /**
     * @param PackageInterface $package
     * @return string
     */
    function getInstallPath(PackageInterface $package);
} 
