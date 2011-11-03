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

namespace Composer\Downloader;

use Composer\Package\PackageInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class GitDownloader implements DownloaderInterface
{
    /**
     * {@inheritDoc}
     */
    public function getInstallationSource()
    {
        return 'source';
    }

    /**
     * {@inheritDoc}
     */
    public function download(PackageInterface $package, $path)
    {
        if (!$package->getSourceReference()) {
            throw new \InvalidArgumentException('The given package is missing reference information');
        }

        if (!extension_loaded('openssl')) {
            throw new \RuntimeException('You must enable the openssl extension to clone git repositories');
        }

        $url = escapeshellarg($package->getSourceUrl());
        $ref = escapeshellarg($package->getSourceReference());
        system(sprintf('git clone %s %s && cd %2$s && git reset --hard %s', $url, $path, $ref));
    }

    /**
     * {@inheritDoc}
     */
    public function update(PackageInterface $initial, PackageInterface $target, $path)
    {
        if (!$target->getSourceReference()) {
            throw new \InvalidArgumentException('The given package is missing reference information');
        }

        $this->enforceCleanDirectory($path);
        system(sprintf('cd %s && git fetch && git reset --hard %s', $path, $target->getSourceReference()));
    }

    /**
     * {@inheritDoc}
     */
    public function remove(PackageInterface $package, $path)
    {
        $this->enforceCleanDirectory($path);
        $fs = new Util\Filesystem();
        $fs->remove($path);
    }

    private function enforceCleanDirectory($path)
    {
        exec(sprintf('cd %s && git status -s', $path), $output);
        if (implode('', $output)) {
            throw new \RuntimeException('Source directory has uncommitted changes');
        }
    }
}
