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
    public function distDownload(PackageInterface $package, $path)
    {
        $url = escapeshellarg($package->getDistUrl());
        $ref = escapeshellarg($package->getDistReference());
        system(sprintf('git archive --format=tar --prefix=%s --remote=%s %s | tar -xf -', $path, $url, $ref));
    }

    /**
     * {@inheritDoc}
     */
    public function sourceDownload(PackageInterface $package, $path)
    {
        if (!$package->getSourceReference()) {
            throw new \InvalidArgumentException('The given package is missing reference information');
        }

        $url = escapeshellarg($package->getSourceUrl());
        $ref = escapeshellarg($package->getSourceReference());
        system(sprintf('git clone %s %s && cd %2$s && git reset --hard %s', $url, $path, $ref));
    }

    /**
     * {@inheritDoc}
     */
    public function distUpdate(PackageInterface $initial, PackageInterface $target, $path)
    {
        throw new \Exception('Updating dist installs from git is not implemented yet');
    }

    /**
     * {@inheritDoc}
     */
    public function sourceUpdate(PackageInterface $initial, PackageInterface $target, $path)
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
