<?php


namespace Composer\Installer;
use Composer\Package\PackageInterface;

class FLOW3Installer extends LibraryInstaller {

    /**
     * {@inheritDoc}
     */
    public function supports($packageType)
    {
    	var_dump("SUPPORTS CALLED");
    	return ($packageType === 'flow3-package');
    }

    /**
     * {@inheritDoc}
     */
    public function getInstallPath(PackageInterface $package)
    {
    	var_dump("getInstallPath called");
		$extra = $package->getExtra();
		if (!isset($extra['packageKey'])) {
			throw new \Exception("extra[packageKey] must be set for FLOW3 packages");
		}
		if (!isset($extra['suggestedLocation'])) {
			throw new \Exception("extra[suggestedLocation] must be set for FLOW3 packages");
		}
		$path = realpath('Packages') . '/' . $extra['suggestedLocation'] . '/' . $extra['packageKey'];
        return $path;
    }
}