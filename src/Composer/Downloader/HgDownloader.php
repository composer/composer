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
use Composer\Downloader\Util\Filesystem;

/**
 * @author Per Bernhardt <plb@webfactory.de>
 */
class HgDownloader implements DownloaderInterface
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

        $url = escapeshellarg($package->getSourceUrl());
        $ref = escapeshellarg($package->getSourceReference());
        Filesystem::runProcess(sprintf('(hg clone %s %s  2> /dev/null) && cd %2$s && hg up %s', $url, $path, $ref));
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
        Filesystem::runProcess(sprintf('cd %s && hg pull && hg up %s', $path, escapeshellarg($target->getSourceReference())));
    }

    /**
     * {@inheritDoc}
     */
    public function remove(PackageInterface $package, $path)
    {
        $this->enforceCleanDirectory($path);
        $fs = new Util\Filesystem();
        $fs->removeDirectory($path);
    }

    private function enforceCleanDirectory($path)
    {
        Filesystem::runProcess(sprintf('cd %s && hg st', $path), $output);
        if (implode('', $output)) {
            throw new \RuntimeException('Source directory has uncommitted changes');
        }
    }
}
